#!/usr/local/bin/php
<?php
// This runs isolated via configd. Safe to use legacy includes.
require_once("config.inc");
require_once("interfaces.inc");

try {
    interfaces_vlan_configure();
    interfaces_setup();
    echo "OK";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

?>
