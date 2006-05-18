<?php

/**
 * This file is included to check whether this is a known team, and sets
 * the $login variable accordingly. It checks this by the IP from the
 * database, if not present it returns an error 403 (Forbidden).
 */

$ip = $_SERVER['REMOTE_ADDR'];
$row = $DB->q('MAYBETUPLE SELECT * FROM team WHERE ipaddress = %s', $ip);

// not found in database
if(!$row) {
	header('HTTP/1.1 403 Forbidden');
	$title = '403 Forbidden';
	include('../header.php');
	echo "<h1>403 Forbidden</h1>\n\n<p>Sorry, access not allowed for " .
		htmlspecialchars($_SERVER['REMOTE_ADDR']) . ".</p>\n\n";
	putDOMjudgeVersion();
	include('../footer.php');
	exit;
}

// make the following fields available for the scripts
$login = $row['login'];
$name = $row['name'];

// is this the first visit? record that in the team table
if ( empty($row['teampage_first_visited']) ) {
	$DB->q('UPDATE team SET teampage_first_visited = NOW() '.
		'WHERE login = %s', $login);
}

unset($row);

