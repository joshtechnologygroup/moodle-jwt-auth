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
 * Anobody can login with any password.
 *
 * @package auth_jwt
 * @author Martin Dougiamas
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

        global $CFG, $DB;

        $authtoken = null;

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authtoken = substr($headers['Authorization'], 7);
            }
        }

        echo('Payload Decoded: ' . print_r($authtoken, true));

        if (!isset($authtoken))
            return;

        $token_parts = explode('.', $authtoken);

        $headerEncoded = $token_parts[0];
        $payloadEncoded = $token_parts[1];
        $signatureEncoded = $token_parts[2];

        $payload = $this->parse_jwt_component($payloadEncoded);

        echo('Payload Encoded: ' . print_r($payloadEncoded, true));
        echo('Payload Decoded: ' . print_r($payload, true));

        $userUsername = $payload->preferred_username;
        $userExists = $DB->record_exists('user', ["username" => $userUsername]);

        echo('User Exists?: ' . print_r($userExists, true));

        if (!$userExists) {
            $user = create_user_record($userUsername, null, "jwt");

            $user->email = $payload->email;
            $user->firstname = $payload->given_name;
            $user->lastname = $payload->family_name;
            $user->mnethostid = $CFG->mnet_localhost_id;

            $user->confirmed = true;
            $user->policyagreed = true;

            $DB->update_record("user", $user);

            echo('User Created: ' . print_r($user, true));
        }

        $updatedUser = $DB->get_record("user", ["username" => $userUsername, "auth" => "jwt"]);

        complete_user_login($updatedUser);
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

