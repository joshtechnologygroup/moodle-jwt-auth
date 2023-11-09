<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Auth using a JWT on a protected Moodle instance.
 *
 * @package auth_jwt
 * @author Trey Hayden <trey.hayden.ctr@adlnet.gov>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/authlib.php');

/**
 * Plugin for no authentication.
 */
class auth_plugin_jwt extends auth_plugin_base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'jwt';
        $this->config = get_config('auth_jwt');
    }

    /**
     * Old syntax of class constructor. Deprecated in PHP7.
     *
     * @deprecated since Moodle 3.1
     */
    public function auth_plugin_jwt() {
        debugging('Use of class name as constructor is deprecated', DEBUG_DEVELOPER);
        self::__construct();
    }

    /**
     * Catch the initial request before we send the user to a login page.
     * 
     * This will be our primary way of checking the JWT Bearer token used in the
     * Authorization header.
     */
    public function pre_loginpage_hook() {
        $this->attempt_jwt_login();
    }

    /**
     * Catch the request a login page is actually loaded manually.
     * 
     * This will allow a user to automatically log-in when pressing the
     * dashboard's Log In button.
     */
    public function loginpage_hook() {
        $this->attempt_jwt_login();
    }

    private function attempt_jwt_login() {
        global $CFG, $DB;

        $authtoken = null;
        $authtokenRaw = null;

        /**
         * Most deployments will be through Apache, at least for ADL, so
         * we can leverage a convenient getter to help out.
         */
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authtokenRaw = $headers['Authorization'];
            }
        }

        /**
         * Older versions of Moodle and those without an Apache server base
         * will miss the previous check, so we can also check the older syntax
         * if necessary.
         */
        if (!isset($authtokenRaw)) {
            if (isset($_SERVER['Authorization'])) {
                $authtokenRaw = $_SERVER['Authorization'];
            }
            else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $authtokenRaw = $_SERVER['HTTP_AUTHORIZATION'];
            } 
        }

        if (!isset($authtokenRaw))
            return;
        
        $authtoken = trim(substr($authtokenRaw, 7));
        $token_parts = explode('.', $authtoken);

        $headerEncoded = $token_parts[0];
        $payloadEncoded = $token_parts[1];
        $signatureEncoded = $token_parts[2];

        $payload = $this->parse_jwt_component($payloadEncoded);
        
        /**
         * We allow the environment to specify whether to perform an issuer check.
         * 
         * For some environments, this will be necessary, but for ADL's P1 deployment
         * this doesn't add any extra security.
         */
        $checkIssuer = getenv("MOODLE_JWT_CHECK_ISSUER");
        if (isset($checkIssuer) && $checkIssuer) {

            $issuer = $payload->iss;
            $issuerExpected = getenv("MOODLE_JWT_ISSUER");

            if ($issuer != $issuerExpected)
                return;
        }

        /**
         * Similarly for the token's client value.
         * 
         * For Client, this is a bit less obvious as these can be auto-generated by the
         * deployment environment and should be provided by the Ops / Hosting team.
         */
        $checkClient = getenv("MOODLE_JWT_CHECK_CLIENT");
        if (isset($checkClient) && $checkClient) {

            $client = $payload->azp;
            $clientExpected = getenv("MOODLE_JWT_CLIENT_ID");

            if ($client != $clientExpected)
                return;
        }

        $userExists = $DB->record_exists('user', ["email" => $payload->email]);

        if (!$userExists) {

            $username = $this->get_expected_username($payload);
            $password = null;

            /**
             * As of Moodle 4.3, created user passwords can no longer be null.
             * 
             * Since this auth method does not allow manual logins anyway, the
             * approach will be to simply create a pseudo-randomized password
             * for this account, which will be blocked from manual entry anyway.
             */
            $assignRandomPassword = getenv("MOODLE_JWT_ASSIGN_RANDOM_PASSWORD");
            if (isset($assignRandomPassword) && $assignRandomPassword) {

                /**
                 * The "salt" here will simply be a character block to satisfy password reqs.
                 * 
                 * The Nonce, Issuer, and JWT ID are all relatively complex, so we will simply
                 * concatenate them with the requirements to ensure Moodle accepts it.
                 */
                $requirementSalt = "aA_12345678";
                $password = $payload->iss . $payload->sub . $payload->nonce . $requirementSalt;
            }

            $user = create_user_record($username, $password, "jwt");

            $user->email = $payload->email;
            $user->firstname = $payload->given_name;
            $user->lastname = $payload->family_name;
            $user->mnethostid = $CFG->mnet_localhost_id;

            $user->confirmed = true;
            $user->policyagreed = true;

            $DB->update_record("user", $user);
        }
        else {
            $existingUser = $DB->get_record("user", ["email" => $payload->email]);
            $expectedUsername = $this->get_expected_username($payload);

            if ($existingUser->username != $expectedUsername) {
                $existingUser->username = $expectedUsername;

                $DB->update_record("user", $existingUser);
            }
        }

        $updatedUser = $DB->get_record("user", ["email" => $payload->email]);

        /**
         * This call will automatically complete the user's login process,
         * so if that doesn't happen then something else failed above.
         */
        complete_user_login($updatedUser);
    }

    /**
     * Use the information provided in the cert + environment variables to determine
     * the expected username for this account.
     * 
     * If nothing is set to manipulate this, it will return the 'preferred_username'
     * property with no modifications.
     */
    private function get_expected_username($cert) {

        $envUseEDIPI = getenv("MOODLE_JWT_USE_EDIPI_NUMBER");
        $envEDIPIProperty = getenv("MOODLE_JWT_EDIPI_PROPERTY");

        $useEDIPI = isset($envUseEDIPI) && strcasecmp($envUseEDIPI, "true");
        $configuredForEDIPI =  isset($envEDIPIProperty);

        if ($useEDIPI && $configuredForEDIPI) {
            $edipiNumber = $this->get_edipi_number($cert, $envEDIPIProperty);
            $foundEDIPI = !is_null($edipiNumber);

            if ($foundEDIPI) {
                return $edipiNumber;
            }
        }
        
        $envCustomProperty = getenv("MOODLE_JWT_USERNAME_PROPERTY");
        
        $useCustomProperty = isset($envCustomProperty);
        $hasCustomProperty = property_exists($cert, $envCustomProperty);

        if ($useCustomProperty && $hasCustomProperty) {
            return $cert->$envCustomProperty;
        }

        return $this->get_default_username($cert);
    }

    /**
     * Parses a given property of the cert for an EDIPI number.
     * 
     * This will return the default username
     */
    private function get_edipi_number($cert, $edipiProperty) {
        $edipiRaw = $cert->$edipiProperty;
        $edipiParts = explode(".", $edipiRaw);
        $edipiLastPart = end($edipiParts);

        $edipiNumber = preg_replace("/[^0-9]/", "", $edipiLastPart);
        $hasCorrectLength = strlen($edipiNumber) == 10;

        if ($hasCorrectLength) {
            return $edipiNumber;
        }

        return null;
    }

    /**
     * Optionally use a different property for the username, controlled through
     * an environment variable.  
     * 
     * Ignored if not set, will use the standard 'preferred_username' instead.
     */
    private function get_default_username($cert) {
        return $cert->preferred_username;
    }

    private function parse_jwt_component($encodedStr) {

        $decodedStr = $this->decode_base_64($encodedStr);
        $jsonObj = json_decode($decodedStr);

        return $jsonObj;
    }

    private function decode_base_64($encodedStr) {

        $a = str_replace('-','+', $encodedStr);
        $b = str_replace('_', '/', $a);

        return base64_decode($b);
    }

    /**
     * Unused atm.
     */
    private function ensure_user_is_site_admin($user) {

        global $DB;

        $adminRecord = $DB->get_record('config', ["name" => "siteadmins"]);
        $siteAdmins = explode(",", $adminRecord->value);

        $alreadyAdmin = in_array(strval($user->id), $siteAdmins);
        if ($alreadyAdmin) 
            return;

        array_push($siteAdmins, $user->id);
        $adminRecord->value = implode(",", $siteAdmins);

        $DB->update_record("config", $adminRecord);
    }

    /**
     * Returns true if the username and password work or don't exist and false
     * if the user exists and the password is wrong.
     *
     * @param string $username The username
     * @param string $password The password
     * @return bool Authentication success or failure.
     */
    function user_login ($username, $password) {

        // global $DB;
        // return $DB->record_exists('user', [
        //     "username" => $username,
        //     "auth" => "jwt"
        // ]);

        return false;
    }

    /**
     * Updates the user's password.
     *
     * called when the user password is updated.
     *
     * @param  object  $user        User table object
     * @param  string  $newpassword Plaintext password
     * @return boolean result
     *
     */
    function user_update_password($user, $newpassword) {
        $user = get_complete_user_data('id', $user->id);
        // This will also update the stored hash to the latest algorithm
        // if the existing hash is using an out-of-date algorithm (or the
        // legacy md5 algorithm).
        return update_internal_user_password($user, $newpassword);
    }

    function prevent_local_passwords() {
        return false;
    }

    /**
     * Returns true if this authentication plugin is 'internal'.
     *
     * @return bool
     */
    function is_internal() {
        return true;
    }

    /**
     * Returns true if this authentication plugin can change the user's
     * password.
     *
     * @return bool
     */
    function can_change_password() {
        return true;
    }

    /**
     * Returns the URL for changing the user's pw, or empty if the default can
     * be used.
     *
     * @return moodle_url
     */
    function change_password_url() {
        return null;
    }

    /**
     * Returns true if plugin allows resetting of internal password.
     *
     * @return bool
     */
    function can_reset_password() {
        return true;
    }

    /**
     * Returns true if plugin can be manually set.
     *
     * @return bool
     */
    function can_be_manually_set() {
        return true;
    }

}


