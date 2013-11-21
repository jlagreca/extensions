#!/usr/bin/php
<?php

define("SQUID_ROOT", dirname(__file__));
require_once (SQUID_ROOT . "/common.php");

function writeLog($message, $verbose = false)
{
    global $pid;

    if (( ! $verbose || SQUID_LOG_VERBOSE) && SQUID_LOG_FILE)
    {
        // let echo handle file locking - PHP streams not suited to this
        shell_exec("echo \"[" . date("r") . "] #$pid: $message\" >> \"" . SQUID_LOG_FILE . "\"");
    }
}

function writeReply($reply)
{
    global $count, $time, $requestStart, $requestEnd;
    fwrite(STDOUT, "$reply\n");
    $requestEnd   = microtime(true);
    $requestTime  = $requestEnd - $requestStart;
    $time        += $requestTime;
    $count++;
    writeLog("Reply: $reply (processed in {$requestTime}s)", true);
}

function cleanUp()
{
    global $ad, $pconn;

    if (isset($ad))
    {
        ldap_unbind($ad);
        unset($GLOBALS["ad"]);
    }

    if (isset($pconn))
    {
        pg_close($pconn);
        unset($GLOBALS["conn"]);
    }
}

$pid    = getmypid();
$start  = microtime(true);
$count  = 0;
$time   = 0;
$pmcs   = "host=" . SQUID_PM_DB_SERVER . " port=" . SQUID_PM_DB_PORT . " dbname=" . SQUID_PM_DB_NAME . " user=" . SQUID_PM_DB_USERNAME . " password='" . addslashes(SQUID_PM_DB_PASSWORD) . "' connect_timeout=" . SQUID_CONNECT_TIMEOUT;

while ( ! feof(STDIN))
{
    cleanUp();
    $inputStr      = trim(fgets(STDIN));
    $requestStart  = microtime(true);
    $requestEnd    = null;

    if ( ! $inputStr)
    {
        continue;
    }

    writeLog("Request: $inputStr", true);

    // ttl = time to live (if we cache this result)
    $ttl = SQUID_DEFAULT_TTL;

    // get client IP and MAC for starters
    $input  = explode(" ", $inputStr);
    $srcIP  = $input[0];

    // we could do more sanity checks here, but Squid is a trustworthy input source
    if ( ! $srcIP)
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Invalid input to external_auth. IP address expected.\"");

        continue;
    }

    $arp      = `arp -n $srcIP`;
    $matches  = array();

    if (preg_match("/(([0-9a-f]{1,2}:){5}[0-9a-f]{1,2})/i", $arp, $matches))
    {
        // ensure the MAC address is 17 characters long (OS X hosts don't add leading zeroes)
        $macBytes  = explode(":", strtolower($matches[0]));
        $mac       = "";

        foreach ($macBytes as $macByte)
        {
            if ($mac)
            {
                $mac .= ":";
            }

            if (strlen($macByte) == 2)
            {
                $mac .= $macByte;
            }
            else
            {
                $mac .= "0$macByte";
            }
        }
    }
    else
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Unable to determine client MAC address.\"");

        continue;
    }

    // TODO: check memcached
    // check for a match in BYOD database (i.e. fastest query first)
    $mconn = mysqli_connect(SQUID_BYOD_DB_SERVER, SQUID_BYOD_DB_USERNAME, SQUID_BYOD_DB_PASSWORD, SQUID_BYOD_DB_NAME);

    if ( ! mysqli_connect_error())
    {
        $rs = mysqli_query($mconn, "select username, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expiry_time_utc) as ttl from auth_sessions where mac_address = '$mac' and ip_address = '$srcIP' and expiry_time_utc > UTC_TIMESTAMP()");

        if ($rs && ($row = $rs->fetch_row()))
        {
            // enforce the session expiry time
            $ttl = $row[1] + 0;

            if ( ! isset($input[1]))
            {
                // TODO: cache result
                writeReply("OK user=$row[0]");

                continue;
            }
            else
            {
                // TODO: group membership checks
            }
        }
    }

    // connect to Profile Manager database
    if (($pconn = pg_connect($pmcs)) === false || pg_prepare($pconn, "get_user_GUID", "SELECT users.guid FROM devices inner join users on devices.user_id = users.id WHERE lower(\"WiFiMAC\") = \$1") === false)
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Unable to connect to Profile Manager database.\"");

        continue;
    }

    // bind to LDAP server
    if (($ad = ldap_connect(SQUID_LDAP_SERVER)) === false || ! ldap_bind($ad, SQUID_LDAP_USER_DN, SQUID_LDAP_USER_PW))
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Unable to bind to LDAP server.\"");

        continue;
    }

    // check for a matching GUID
    if (($result = pg_execute($pconn, "get_user_GUID", array($mac))) === false)
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Unable to retrieve data from Profile Manager database.\"");

        continue;
    }

    if (($guid = pg_fetch_row($result)) !== false)
    {
        // we have our GUID - now to search for a match in LDAP (but first we'll need to re-format the GUID)
        $guid     = str_replace("-", "", $guid[0]);
        $guid     = str_split($guid, 2);
        $bytes    = array();
        $bytes[]  = $guid[3];
        $bytes[]  = $guid[2];
        $bytes[]  = $guid[1];
        $bytes[]  = $guid[0];
        $bytes[]  = $guid[5];
        $bytes[]  = $guid[4];
        $bytes[]  = $guid[7];
        $bytes[]  = $guid[6];
        $bytes    = array_merge($bytes, array_slice($guid, 8));
        $guid     = "\\" . implode("\\", $bytes);
        $query    = "(objectGUID=$guid)";

        if (isset($input[1]))
        {
            if (isset($SQUID_LDAP_GROUP_DN[$input[1]]))
            {
                // this is a special memberOf query that checks membership recursively (may only work on Active Directory)
                $query = "(&(objectGUID=$guid)(memberOf:1.2.840.113556.1.4.1941:=" . $SQUID_LDAP_GROUP_DN[$input[1]] . "))";
            }
            else
            {
                writeReply(SQUID_FAILURE_CODE . " message=\"No matching group DN found for '$input[1]'.\"");

                continue;
            }
        }

        $ls = ldap_search($ad, SQUID_LDAP_BASE_DN, $query, array("sAMAccountName"), 0, 0, SQUID_CONNECT_TIMEOUT);

        if ($ls === false || ($r = ldap_get_entries($ad, $ls)) === false)
        {
            writeReply(SQUID_FAILURE_CODE . " message=\"Unable to retrieve data from LDAP server.\"");

            continue;
        }

        // finally, we have our username!
        if (isset($r[0]["samaccountname"][0]))
        {
            $username = $r[0]["samaccountname"][0];
            writeReply("OK user=$username");

            continue;
        }
    }

    writeReply("ERR");
}

writeLog("$count requests processed, average processing time " . ($time / $count) . "s");

// PRETTY_NESTED_ARRAYS,0

?>