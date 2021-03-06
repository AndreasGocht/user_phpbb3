<?php

/**
 * ownCloud
 *
 * @author Patrik Karisch
 * @copyright 2012 Patrik Karisch <patrik.karisch@abimus.com>
 *
 * @author Carl P. Corliss
 * @copyright 2014 Carl P. Corliss <rabbitt@gmail.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

use OCA\user_phpbb3\lib\Helpers;

class OC_User_Phpbb3 extends OC_User_Backend implements \OCP\UserInterface {

	private $config;
	private $connected;
	private $db;

	function __construct() {
		$this->connected = false;
		$this->config = array(
			'host'   => OC_Appconfig::getValue('user_phpbb3', 'phpbb3_db_host',      'localhost'),
			'name'   => OC_Appconfig::getValue('user_phpbb3', 'phpbb3_db_name',      'phpbb3'),
			'user'   => OC_Appconfig::getValue('user_phpbb3', 'phpbb3_db_user',      ''),
			'pass'   => OC_Appconfig::getValue('user_phpbb3', 'phpbb3_db_pass',      ''),
			'prefix' => OC_Appconfig::getValue('user_phpbb3', 'phpbb3_db_prefix',    'phpbb3_'),
			'group'  => OC_Appconfig::getValue('user_phpbb3', 'phpbb3_assign_group', 'phpbb3'),
		);

		try {
			$this->db = new PDO(
				sprintf("mysql:host=%s;dbname=%s;", $this->config['host'], $this->config['name']),
				$this->config['user'], $this->config['pass']
			);
		} catch (PDOException $e) {
			$this->logError(sprintf('Failed to connect to phpbb3 database: %s', $e->getMessage()));
			return false;
		}

		if (!empty($this->config['group']) && ! \OC_Group::groupExists($this->config['group'])) {
			\OC_Group::createGroup($this->config['group']);
		}

		$this->connected = true;
	}

	/**
	 * Log a message with log level ERROR to the owncloud log
	 * @param $message the message to log
	 */
	private function logError($message) {
		\OCP\Util::writeLog('user_phpbb3', $message, \OCP\Util::ERROR);
	}

	/**
	 * Log a message with log level INFO to the owncloud log
	 * @param $message the message to log
	 */
	private function logInfo($message) {
		\OCP\Util::writeLog('user_phpbb3', $message, \OCP\Util::INFO);
	}

	/**
	 * Log a message with log level DEBUG to the owncloud log
	 * @param $message the message to log
	 */
	private function logDebug($message) {
		\OCP\Util::writeLog('user_phpbb3', $message, \OCP\Util::DEBUG);
	}

	/**
	 * Return normalized (prefixed) tablename
	 * @param $table The tablename to normalize
	 */
	private function normalizeTableName($table) {
		if (!$this->connected) { return null; }
		if ($this->config['prefix']) {
			return $this->db->real_escape_string(sprintf("%s_%s", $this->config['prefix'], $table));
		} else {
			return $table;
		}
	}

	/**
	 * Executes a query against the database, returning the results
	 * @param $query The query to perform
	 * @param $bindings Bindings, if any, to pass to execute
	 */
	private function query($query, $bindings = array()) {
		if (!$this->connected) { return array(); }
		$stmt = $this->db->prepare($query);
		$stmt->execute($bindings);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * @brief Check if the password is correct
	 * @param $uid The username
	 * @param $password The password
	 * @returns true/false
	 */
	public function checkPassword($uid, $password){
		$result = $this->query("
			SELECT username AS uid, user_email AS email, user_password AS hash
			  FROM {$this->normalizeTableName('users')}
			 WHERE username = ? AND user_type IN (0, 3)
			", array($uid));

		if (count($result) == 1) {
			$user = array_shift($result);
			if (OCA\user_phpbb3\lib\Helper::phpbb_check_hash($password, $user['hash'])) {
				OC_Preferences::setValue($uid, 'settings', 'email', $user['email']);
				$this->logInfo("User '$uid' passed password check.");
				$this->updateUserData($user['uid']);
				return $user['uid'];
			} else {
				$this->logInfo("Couldn't verify user '$uid' password against phpbb3 backend.");
			}
		} else {
			$this->logInfo("Couldn't find user '$uid' in the phpbb3 backend.");
		}

		return false;
	}

	private function updateUserData($uid) {
		if (!empty($this->config['group']) && !\OC_Group::inGroup($uid, $this->config['group'])) {
			$this->logInfo("Adding user to group " . $this->config['group']);
			\OC_Group::addToGroup($uid, $this->config['group']);
		}

		try {
			$avatar = new \OC_Avatar($uid);
			if (!$avatar->get() && $avatar_data = $this->getRemoteAvatar($uid)) {
				$avatar_size = strlen($avatar_data);
				$this->logInfo("Setting initial avatar for user $uid - retreived image of ${avatar_size} bytes.");
				$avatar->set($avatar_data);
			}
		} catch (Exception $e) {
			$this->logError(
				sprintf("Failed to import phpbb3 avatar: %s: %s", get_class($e), $e->getMessage())
			);
		}
	}

	private function getRemoteAvatar($uid) {
			$users_table  = $this->normalizeTableName('users');
			$config_table = $this->normalizeTableName('config');

			$result = $this->query("
				SELECT CONCAT(
				    proto.config_value,
				    host.config_value,
				    ':',
				    port.config_value,
				    path.config_value,
				    '/download/file.php?avatar=',
				    u.user_avatar
				  ) AS avatar_url
				  FROM $users_table AS u
				  LEFT JOIN $config_table AS proto ON proto.config_name = 'server_protocol'
				  LEFT JOIN $config_table AS host ON host.config_name = 'server_name'
				  LEFT JOIN $config_table AS port ON port.config_name = 'server_port'
				  LEFT JOIN $config_table AS path ON path.config_name = 'script_path'
				  WHERE u.username = ?;
			", array($uid));

			if (count($result) != 1) return NULL;
			return @file_get_contents($result[0]['avatar_url']);
	}

	/**
	 * @brief Get a list of all users
	 * @returns array with all uids
	 *
	 * Get a list of all users
	 */
	public function getUsers($search = '', $limit = 10, $offset = 0) {
		$result = $this->query("
			SELECT username
			  FROM {$this->normalizeTableName('users')}
			 WHERE user_type IN (0, 3)
			");

		$users = array();
		array_walk_recursive($result, function($u) use(&$users) { $users[] = $u; });
		sort($users);
		return $users;
	}

	/**
	 * @brief check if a user exists
	 * @param string $uid the username
	 * @return boolean
	 */
	public function userExists($uid) {
		$result = $this->query("
			SELECT username
			  FROM {$this->normalizeTableName('users')}
			 WHERE username = ? AND user_type IN (0, 3)
			", array($uid));

		return count($result) > 0;
	}

	/**
	* delete a user
	* @param string $uid The username of the user to delete
	* @return bool
	*
	* Deletes a user
	*/
	public function deleteUser($uid) {
		return false;
	}

	/**
	 * @return bool
	 */
	public function hasUserListings() {
		return true;
	}

	/**
	 * counts the users in LDAP
	 *
	 * @return int|bool
	 */
	public function countUsers() {
		return count($this->getUsers());
	}

	/**
	* Check if backend implements actions
	* @param int $actions bitwise-or'ed actions
	* @return boolean
	*
	* Returns the supported actions as int to be
	* compared with OC_USER_BACKEND_CREATE_USER etc.
	*/
	public function implementsActions($actions) {
		return (bool) (
			(OC_USER_BACKEND_CHECK_PASSWORD | OC_USER_BACKEND_COUNT_USERS) &
			$actions
		);
	}
}
