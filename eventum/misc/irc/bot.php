<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 encoding=utf-8: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003, 2004, 2005, 2006, 2007 MySQL AB                              |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: João Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+
//
// @(#) $Id: bot.php 3210 2007-01-29 08:50:08Z balsdorf $
//

ini_set('memory_limit', '256M');

require_once('../../init.php');

if (!file_exists(APP_CONFIG_PATH . 'irc_config.php')) {
    echo "ERROR: No config specified. Please see setup/irc_config.php for config information.\n\n";
    exit;
}


// ============================================
// ============================================
// NO NEED TO UPDATE ANYTHING BELOW THIS LINE
// ============================================
// ============================================

require_once(APP_INC_PATH . 'db_access.php');
require_once(APP_INC_PATH . 'class.auth.php');
require_once(APP_INC_PATH . 'class.lock.php');
require_once(APP_INC_PATH . 'class.issue.php');
require_once(APP_INC_PATH . 'class.user.php');
require_once(APP_PEAR_PATH . 'Net/SmartIRC.php');

// if requested, clear the lock
if (in_array('--fix-lock', @$_SERVER['argv'])) {
    Lock::release('irc_bot');
    echo "The lock file was removed successfully.\n";
    exit;
}

// acquire a lock to prevent multiple scripts from
// running at the same time
if (!Lock::acquire('irc_bot')) {
    echo 'Error: Another instance of the script is still running. ',
                "If this is not accurate, you may fix it by running this script with '--fix-lock' ",
                "as the only parameter.\n";
    exit;
}

$auth = array();

// map project_id => channel(s)
$channels = array();
foreach ($irc_channels as $proj => $chan) {
    $proj_id = Project::getID($proj);
    $channels[$proj_id] = is_array($chan) ? $chan : array($chan);
}

class Eventum_Bot
{
    function _isAuthenticated(&$irc, &$data)
    {
        global $auth;

        if (in_array($data->nick, array_keys($auth))) {
            return true;
        } else {
            $this->sendResponse($irc, $data->nick, 'Error: You need to be authenticated to run this command.');
            return false;
        }
    }


    function _getEmailByNickname($nickname)
    {
        global $auth;

        if (in_array($nickname, array_keys($auth))) {
            return $auth[$nickname];
        } else {
            return '';
        }
    }


    function clockUser(&$irc, &$data)
    {
        if (!$this->_isAuthenticated($irc, $data)) {
            return;
        }
        $email = $this->_getEmailByNickname($data->nick);

        $pieces = explode(' ', $data->message);
        if ((count($pieces) == 2) && ($pieces[1] != 'in') && ($pieces[1] != 'out')) {
            $this->sendResponse($irc, $data->nick, 'Error: wrong parameter count for "CLOCK" command. Format is "!clock [in|out]".');
            return;
        }
        if (@$pieces[1] == 'in') {
            $res = User::clockIn(User::getUserIDByEmail($email));
        } elseif (@$pieces[1] == 'out') {
            $res = User::clockOut(User::getUserIDByEmail($email));
        } else {
            if (User::isClockedIn(User::getUserIDByEmail($email))) {
                $msg = "clocked in";
            } else {
                $msg = "clocked out";
            }
            $this->sendResponse($irc, $data->nick, "You are currently $msg.");
            return;
        }
        if ($res == 1) {
            $this->sendResponse($irc, $data->nick, 'Thank you, you are now clocked ' . $pieces[1] . '.');
        } else {
            $this->sendResponse($irc, $data->nick, 'Error clocking ' . $pieces[1] . '.');
        }
    }


    function listClockedInUsers(&$irc, &$data)
    {
        if (!$this->_isAuthenticated($irc, $data)) {
            return;
        }

        $list = User::getClockedInList();
        if (count($list) == 0) {
            $this->sendResponse($irc, $data->nick, 'There are no clocked-in users as of now.');
        } else {
            $this->sendResponse($irc, $data->nick, 'The following is the list of clocked-in users:');
            foreach ($list as $name => $email) {
                $this->sendResponse($irc, $data->nick, "$name: $email");
            }
        }
    }


    function listQuarantinedIssues(&$irc, &$data)
    {
        if (!$this->_isAuthenticated($irc, $data)) {
            return;
        }

        $list = Issue::getQuarantinedIssueList();
        if (count($list) == 0) {
            $this->sendResponse($irc, $data->nick, 'There are no quarantined issues as of now.');
        } else {
            $this->sendResponse($irc, $data->nick, 'The following are the details of the ' . count($list) . ' quarantined issue(s):');
            for ($i = 0; $i < count($list); $i++) {
                $url = APP_BASE_URL . 'view.php?id=' . $list[$i]['iss_id'];
                $msg = sprintf('Issue #%d: %s, Assignment: %s, %s', $list[$i]['iss_id'], $list[$i]['iss_summary'], $list[$i]['assigned_users'], $url);
                $this->sendResponse($irc, $data->nick, $msg);
            }
        }
    }


    function listAvailableCommands(&$irc, &$data)
    {
        $commands = array(
            'auth'             => 'Format is "auth user@example.com password"',
            'clock'            => 'Format is "clock [in|out]"',
            'list-clocked-in'  => 'Format is "list-clocked-in"',
            'list-quarantined' => 'Format is "list-quarantined"'
        );
        $this->sendResponse($irc, $data->nick, "This is the list of available commands:");
        foreach ($commands as $command => $description) {
            $this->sendResponse($irc, $data->nick, "$command: $description");
        }
    }


    function _updateAuthenticatedUser(&$irc, &$data)
    {
        global $auth;

        $old_nick = $data->nick;
        $new_nick = $data->message;
        if (in_array($data->nick, array_keys($auth))) {
            $auth[$new_nick] = $auth[$old_nick];
            unset($auth[$old_nick]);
        }
    }


    function _removeAuthenticatedUser(&$irc, &$data)
    {
        global $auth;

        if (in_array($data->nick, array_keys($auth))) {
            unset($auth[$data->nick]);
        }
    }


    function listAuthenticatedUsers(&$irc, &$data)
    {
        global $auth;

        foreach ($auth as $nickname => $email) {
            $this->sendResponse($irc, $data->nick, "$nickname => $email");
        }
    }


    function authenticate(&$irc, &$data)
    {
        global $auth;

        $pieces = explode(' ', $data->message);
        if (count($pieces) != 3) {
            $this->sendResponse($irc, $data->nick, 'Error: wrong parameter count for "AUTH" command. Format is "!auth user@example.com password".');
            return;
        }
        $email = $pieces[1];
        $password = $pieces[2];
        // check if the email exists
        if (!Auth::userExists($email)) {
            $this->sendResponse($irc, $data->nick, 'Error: could not find a user account for the given email address "$email".');
            return;
        }
        // check if the given password is correct
        if (!Auth::isCorrectPassword($email, $password)) {
            $this->sendResponse($irc, $data->nick, 'Error: The email address / password combination could not be found in the system.');
            return;
        }
        // check if the user account is activated
        if (!Auth::isActiveUser($email)) {
            $this->sendResponse($irc, $data->nick, 'Error: Your user status is currently set as inactive. Please contact your local system administrator for further information.');
            return;
        } else {
            $auth[$data->nick] = $email;
            $this->sendResponse($irc, $data->nick, 'Thank you, you have been successfully authenticated.');
            return;
        }
    }


    /**
     * Helper method to get the list of channels that should be used in the
     * notifications
     *
     * @access  private
     * @param   integer $prj_id The project ID
     * @return  array The list of channels
     */
    function _getChannels($prj_id)
    {
        global $channels;
        return $channels[$prj_id];
    }


    /**
     * Helper method to the projects a channel displays messages for.
     *
     * @access  private
     * @param   string $channel The name of the channel
     * @return  array The projects displayed in the channel
     */
    function _getProjectsForChannel($channel)
    {
        global $channels;
        $projects = array();
        foreach ($channels as $prj_id => $prj_channels) {
            foreach ($prj_channels as $prj_channel) {
                if ($prj_channel == $channel) {
                    $projects[] = $prj_id;
                }
            }
        }
        return $projects;
    }


    /**
     * Method used as a callback to send notification events to the proper
     * recipients.
     *
     * @access  public
     * @param   resource $irc The IRC connection handle
     * @return  void
     */
    function notifyEvents(&$irc)
    {
        // check the message table
        $stmt = "SELECT
                    ino_id,
                    ino_iss_id,
                    ino_prj_id,
                    ino_message
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "irc_notice
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 ON
                    iss_id=ino_iss_id
                 WHERE
                    ino_status='pending'";
        $res = $GLOBALS["db_api"]->dbh->getAll($stmt, DB_FETCHMODE_ASSOC);
        for ($i = 0; $i < count($res); $i++) {
            $channels = $this->_getChannels($res[$i]['ino_prj_id']);
            if (count($channels) > 0) {
                foreach ($channels as $channel) {
                    if ($res[$i]['ino_iss_id'] > 0) {
                        $res[$i]['ino_message'] .= ' - ' . APP_BASE_URL . 'view.php?id=' . $res[$i]['ino_iss_id'];
                    } elseif (substr($res[$i]['ino_message'], 0, strlen('New Pending Email')) == 'New Pending Email') {
                        $res[$i]['ino_message'] .= ' - ' . APP_BASE_URL . 'emails.php';
                    }
                    if (count($this->_getProjectsForChannel($channel)) > 1) {
                        // if multiple projects display in the same channel, display project in message
                        $res[$i]['ino_message'] = "[" . Project::getName($res[$i]['ino_prj_id']) . "] " . $res[$i]['ino_message'];
                    }
                    $this->sendResponse($irc, $channel, $res[$i]['ino_message']);
                }
                // mark message as sent
                $stmt = "UPDATE
                            " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "irc_notice
                         SET
                            ino_status='sent'
                         WHERE
                            ino_id=" . $res[$i]['ino_id'];
                $GLOBALS["db_api"]->dbh->query($stmt);
            }
        }
    }


    /**
     * Method used to send a message to the given target.
     *
     * @access  public
     * @param   resource $irc The IRC connection handle
     * @param   string $target The target for this message
     * @param   string $response The message to send
     * @return  void
     */
    function sendResponse(&$irc, $target, $response)
    {
        // XXX: need way to handle messages with length bigger than 255 chars
        if (!is_array($response)) {
            $response = array($response);
        }
        foreach ($response as $line) {
            if (substr($target, 0, 1) != '#') {
                $type = SMARTIRC_TYPE_QUERY;
            } else {
                $type = SMARTIRC_TYPE_CHANNEL;
            }
            $irc->message($type, $target, $line, SMARTIRC_CRITICAL);
            sleep(1);
        }
    }


    function _joinChannels(&$irc)
    {
        foreach ($GLOBALS['channels'] as $prj_id => $channel_list) {
            $irc->join($channel_list);
        }
    }
}

$bot = &new Eventum_Bot();
$irc = &new Net_SmartIRC();
$irc->setDebug(SMARTIRC_DEBUG_ALL);
$irc->setLogdestination(SMARTIRC_FILE);
$irc->setLogfile(APP_IRC_LOG);
$irc->setUseSockets(TRUE);
$irc->setAutoReconnect(TRUE);
$irc->setAutoRetry(TRUE);
$irc->setReceiveTimeout(600);
$irc->setTransmitTimeout(600);

$irc->registerTimehandler(3000, $bot, 'notifyEvents');

// methods that keep track of who is authenticated
$irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!?list-auth', $bot, 'listAuthenticatedUsers');
$irc->registerActionhandler(SMARTIRC_TYPE_NICKCHANGE, '.*', $bot, '_updateAuthenticatedUser');
$irc->registerActionhandler(SMARTIRC_TYPE_KICK|SMARTIRC_TYPE_QUIT|SMARTIRC_TYPE_PART, '.*', $bot, '_removeAuthenticatedUser');
$irc->registerActionhandler(SMARTIRC_TYPE_LOGIN, '.*', $bot, '_joinChannels');

// real bot commands
$irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!?help', $bot, 'listAvailableCommands');
$irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!?auth ', $bot, 'authenticate');
$irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!?clock', $bot, 'clockUser');
$irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!?list-clocked-in', $bot, 'listClockedInUsers');
$irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!?list-quarantined', $bot, 'listQuarantinedIssues');

$irc->connect($irc_server_hostname, $irc_server_port);
if (empty($username)) {
    $irc->login($nickname, $realname);
} elseif (empty($password)) {
    $irc->login($nickname, $realname, 0, $username);
} else {
    $irc->login($nickname, $realname, 0, $username, $password);
}
$irc->listen();
$irc->disconnect();

// release the lock
Lock::release('irc_bot');
