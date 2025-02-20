<?php

/**
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | CONTENTS:                                                             |
 |   Roundcube utilities                                                 |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 +-----------------------------------------------------------------------+
*/

/**
 * Roundcube utilities
 *
 * @package    Webmail
 * @subpackage Utils
 */
class rcmail_utils
{
    public static $db;

    /**
     * Initialize database object and connect
     *
     * @return rcube_db Database instance
     */
    public static function db()
    {
        if (self::$db === null) {
            $rc = rcube::get_instance();
            $db = rcube_db::factory($rc->config->get('db_dsnw'));

            $db->set_debug((bool)$rc->config->get('sql_debug'));

            // Connect to database
            $db->db_connect('w');

            if (!$db->is_connected()) {
                rcube::raise_error("Failed to connect to database", false, true);
            }

            self::$db = $db;
        }

        return self::$db;
    }

    /**
     * Initialize database schema
     *
     * @param string $dir Directory with sql files
     */
    public static function db_init($dir)
    {
        $db    = self::db();
        $error = null;
        $file  = $dir . '/' . $db->db_provider . '.initial.sql';

        if (!file_exists($file)) {
            rcube::raise_error("DDL file $file not found", false, true);
        }

        echo "Creating database schema... ";

        if ($sql = file_get_contents($file)) {
            if (!$db->exec_script($sql)) {
                $error = $db->is_error();
            }
        }
        else {
            $error = "Unable to read file $file or it is empty";
        }

        if ($error) {
            echo "[FAILED]\n";
            rcube::raise_error($error, false, true);
        }
        else {
            echo "[OK]\n";
        }
    }

    /**
     * Update database schema
     *
     * @param string $dir     Directory with sql files
     * @param string $package Component name
     * @param string $ver     Optional current version number
     * @param array  $opts    Parameters (errors, quiet)
     *
     * @return bool True on success, False on failure
     */
    public static function db_update($dir, $package, $ver = null, $opts = [])
    {
        // Check if directory exists
        if (!file_exists($dir)) {
            if (!empty($opts['errors'])) {
                rcube::raise_error("Specified database schema directory doesn't exist.", false, true);
            }
            return false;
        }

        $db = self::db();

        // Read DB schema version from database (if 'system' table exists)
        if (in_array($db->table_name('system'), (array)$db->list_tables())) {
            $version = self::db_version($package);
        }

        // DB version not found, but release version is specified
        if (empty($version) && $ver) {
            // Map old release version string to DB schema version
            // Note: This is for backward compat. only, do not need to be updated
            $map = [
                '0.1-stable' => 1,
                '0.1.1'      => 2008030300,
                '0.2-alpha'  => 2008040500,
                '0.2-beta'   => 2008060900,
                '0.2-stable' => 2008092100,
                '0.2.1'      => 2008092100,
                '0.2.2'      => 2008092100,
                '0.3-stable' => 2008092100,
                '0.3.1'      => 2009090400,
                '0.4-beta'   => 2009103100,
                '0.4'        => 2010042300,
                '0.4.1'      => 2010042300,
                '0.4.2'      => 2010042300,
                '0.5-beta'   => 2010100600,
                '0.5'        => 2010100600,
                '0.5.1'      => 2010100600,
                '0.5.2'      => 2010100600,
                '0.5.3'      => 2010100600,
                '0.5.4'      => 2010100600,
                '0.6-beta'   => 2011011200,
                '0.6'        => 2011011200,
                '0.7-beta'   => 2011092800,
                '0.7'        => 2011111600,
                '0.7.1'      => 2011111600,
                '0.7.2'      => 2011111600,
                '0.7.3'      => 2011111600,
                '0.7.4'      => 2011111600,
                '0.8-beta'   => 2011121400,
                '0.8-rc'     => 2011121400,
                '0.8.0'      => 2011121400,
                '0.8.1'      => 2011121400,
                '0.8.2'      => 2011121400,
                '0.8.3'      => 2011121400,
                '0.8.4'      => 2011121400,
                '0.8.5'      => 2011121400,
                '0.8.6'      => 2011121400,
                '0.9-beta'   => 2012080700,
            ];

            $version = $map[$ver];
        }

        // Assume last version before the 'system' table was added
        if (empty($version)) {
            $version = 2012080700;
        }

        $dir .= '/' . $db->db_provider;
        if (!file_exists($dir)) {
            if (!empty($opts['errors'])) {
                rcube::raise_error("DDL Upgrade files for " . $db->db_provider . " driver not found.", false, true);
            }
            return false;
        }

        $dh     = opendir($dir);
        $result = [];

        while ($file = readdir($dh)) {
            if (preg_match('/^([0-9]+)\.sql$/', $file, $m) && $m[1] > $version) {
                $result[] = $m[1];
            }
        }
        sort($result, SORT_NUMERIC);

        foreach ($result as $v) {
            if (empty($opts['quiet'])) {
                echo "Updating database schema for {$package} ($v)... ";
            }

            // Ignore errors here to print the error only once
            $db->set_option('ignore_errors', true);
            $error = self::db_update_schema($package, $v, "$dir/$v.sql");
            $db->set_option('ignore_errors', false);

            if ($error) {
                if (empty($opts['quiet'])) {
                    echo "[FAILED]\n";
                }
                if (!empty($opts['errors'])) {
                    rcube::raise_error("Error in DDL upgrade $v: $error", false, true);
                }
                return false;
            }
            else if (empty($opts['quiet'])) {
                echo "[OK]\n";
            }
        }

        return true;
    }

    /**
     * Run database update from a single sql file
     */
    protected static function db_update_schema($package, $version, $file)
    {
        $db = self::db();

        // read DDL file
        if ($sql = file_get_contents($file)) {
            if (!$db->exec_script($sql)) {
                return $db->is_error();
            }
        }

        // escape if 'system' table does not exist
        if ($version < 2013011000) {
            return;
        }

        $system_table = $db->table_name('system', true);

        $db->query("UPDATE " . $system_table
            . " SET `value` = ?"
            . " WHERE `name` = ?",
            $version, $package . '-version');

        if (!$db->is_error() && !$db->affected_rows()) {
            $db->query("INSERT INTO " . $system_table
                ." (`name`, `value`) VALUES (?, ?)",
                $package . '-version', $version);
        }

        return $db->is_error();
    }

    /**
     * Get version string for the specified package
     *
     * @param string $package Package name
     *
     * @return null|string Version string
     */
    public static function db_version($package = 'roundcube')
    {
        $db = self::db();

        $db->query("SELECT `value`"
            . " FROM " . $db->table_name('system', true)
            . " WHERE `name` = ?",
            $package . '-version');

        $row     = $db->fetch_array();
        if ($row === false) {
            return null;
        }
        $version = preg_replace('/[^0-9]/', '', $row[0]);

        return $version;
    }

    /**
     * Removes all deleted records older than X days
     *
     * @param int $days Number of days
     */
    public static function db_clean($days)
    {
        $db        = self::db();
        $threshold = date('Y-m-d 00:00:00', time() - $days * 86400);
        $tables    = [
            'contacts',
            'contactgroups',
            'identities',
            'responses',
        ];

        foreach ($tables as $table) {
            $sqltable = $db->table_name($table, true);

            // delete outdated records
            $db->query("DELETE FROM $sqltable WHERE `del` = 1 AND `changed` < ?", $threshold);

            echo $db->affected_rows() . " records deleted from '$table'\n";
        }
    }

    /**
     * Get the user hostname from a command line
     */
    public static function get_host($args)
    {
        $rcmail = rcmail::get_instance();

        if (empty($args['host'])) {
            $hosts = $rcmail->config->get('imap_host');
            if (is_string($hosts)) {
                $args['host'] = $hosts;
            }
            else if (is_array($hosts) && count($hosts) == 1) {
                $args['host'] = reset($hosts);
            }
            else {
                rcube::raise_error("Specify a host name", false, true);
            }
        }

        // host can be a URL like tls://192.168.12.44
        $host_url = parse_url($args['host'], PHP_URL_HOST);
        if ($host_url) {
            $args['host'] = $host_url;
        }

        return $args['host'];
    }

    /**
     * Reindex contacts
     */
    public static function indexcontacts()
    {
        $db = self::db();

        // iterate over all users
        $sql_result = $db->query("SELECT `user_id` FROM " . $db->table_name('users', true) . " ORDER BY `user_id`");
        while ($sql_result && ($sql_arr = $db->fetch_assoc($sql_result))) {
            echo "Indexing contacts for user " . $sql_arr['user_id'] . "...\n";

            $contacts = new rcube_contacts($db, $sql_arr['user_id']);
            $contacts->set_pagesize(9999);

            $result = $contacts->list_records();
            while ($result->count && ($row = $result->next())) {
                unset($row['words']);
                $contacts->update($row['ID'], $row);
            }
        }

        echo "done.\n";
    }

    /**
     * Modify user preferences
     *
     * @param string $name   Option name
     * @param string $value  Option value
     * @param int    $userid Optional user identifier
     * @param string $type   Optional value type (bool, int, string)
     */
    public static function mod_pref($name, $value, $userid = null, $type = 'string')
    {
        $db = self::db();

        if ($userid) {
            $query = '`user_id` = ' . intval($userid);
        }
        else {
            $query = '1=1';
        }

        $type = strtolower($type);

        if ($type == 'bool' || $type == 'boolean') {
            $value = rcube_utils::get_boolean($value);
        }
        else if ($type == 'int' || $type == 'integer') {
            $value = (int) $value;
        }

        // iterate over all users
        $sql_result = $db->query("SELECT * FROM " . $db->table_name('users', true) . " WHERE $query");

        while ($sql_result && ($sql_arr = $db->fetch_assoc($sql_result))) {
            echo "Updating prefs for user " . $sql_arr['user_id'] . "...";

            $user  = new rcube_user($sql_arr['user_id'], $sql_arr);
            $prefs = $old_prefs = $user->get_prefs();

            $prefs[$name] = $value;

            if ($prefs != $old_prefs) {
                $user->save_prefs($prefs, true);
                echo "saved.\n";
            }
            else {
                echo "nothing changed.\n";
            }
        }
    }
}
