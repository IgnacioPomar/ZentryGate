<?php


function zg_activate_plugin ()
{
	global $wpdb, $zentrygateDbVersion;

	$charsetCollate = $wpdb->get_charset_collate ();
	$prefix = $wpdb->prefix;

	require_once (ABSPATH . 'wp-admin/includes/upgrade.php');

	$sqlUsers = "CREATE TABLE {$prefix}zgUsers (
        email VARCHAR(255) NOT NULL,
        name VARCHAR(255) NOT NULL,
        passwordHash VARCHAR(255) DEFAULT NULL,
        isAdmin BOOLEAN DEFAULT 0,
        isEnabled BOOLEAN DEFAULT 1,
        invitationCount INT DEFAULT 0,
        lastLogin DATETIME DEFAULT NULL,
        PRIMARY KEY (email)
    ) $charsetCollate;";

	$sqlEvents = "CREATE TABLE {$prefix}zgEvents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        date DATE NOT NULL,
        sectionsJson LONGTEXT NOT NULL,
        rulesJson LONGTEXT NOT NULL,
        PRIMARY KEY (id)
    ) $charsetCollate;";

	$sqlReservations = "CREATE TABLE {$prefix}zgReservations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        userEmail VARCHAR(255) NOT NULL,
        eventId BIGINT UNSIGNED NOT NULL,
        sectionId VARCHAR(255) NOT NULL,
        status ENUM('confirmed','waiting_list') NOT NULL,
        createdAt DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idxUser (userEmail),
        KEY idxEvent (eventId)
    ) $charsetCollate;";

	$sqlCapacity = "CREATE TABLE {$prefix}zgCapacity (
        eventId BIGINT UNSIGNED NOT NULL,
        sectionId VARCHAR(255) NOT NULL,
        maxCapacity INT NOT NULL,
		usedCapacity INT NOT NULL,
        PRIMARY KEY (eventId, sectionId)
    ) $charsetCollate;";

	dbDelta ($sqlUsers);
	dbDelta ($sqlEvents);
	dbDelta ($sqlReservations);
	dbDelta ($sqlCapacity);

	add_option ('zgDbVersion', $zentrygateDbVersion);
}
