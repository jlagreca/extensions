#!/usr/bin/php
<?php

define("SQUID_ROOT", dirname(__file__));
require_once (SQUID_ROOT . "/common.php");

// clean up all of the iptables chains we administer
iptablesUpdate();

?>