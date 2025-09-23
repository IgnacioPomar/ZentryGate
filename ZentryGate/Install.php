<?php

namespace ZentryGate;

class Install
{


	public static function activate (): void
	{
		self::createSchema ();
		update_option ('zentrygate_db_version', ZENTRYGATE_VERSION_DB);
	}


	/**
	 * Borrar todas las tablas del plugin y crearlas de nuevo.
	 */
	public static function recreateDatabase (): void
	{
		self::dropTables ();
		self::createSchema ();
		update_option ('zentrygate_db_version', ZENTRYGATE_VERSION_DB);
	}


	/**
	 * DROP de tablas del plugin (ajusta la lista si tienes más).
	 */
	private static function dropTables (): void
	{
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Orden: primero las que referencian (reservas) y después maestros.
		$tables = [ "{$prefix}zgReservations", "{$prefix}zgCapacity", "{$prefix}zgUsers", "{$prefix}zgEvents"];

		foreach ($tables as $table)
		{
			// Usamos backticks y IF EXISTS para evitar errores si no existe.
			$wpdb->query ("DROP TABLE IF EXISTS `{$table}`");
		}
	}


	// YAGI:
	public static function createSchema (): void
	{
		global $wpdb;

		$charsetCollate = $wpdb->get_charset_collate ();
		$prefix = $wpdb->prefix;

		require_once (ABSPATH . 'wp-admin/includes/upgrade.php');

		$sqlUsers = "CREATE TABLE {$prefix}zgUsers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    	email VARCHAR(254) NOT NULL,
    	name VARCHAR(255) NOT NULL,
    	passwordHash VARCHAR(255) DEFAULT NULL,
    	emailVerifiedAt DATETIME NULL DEFAULT NULL,
    	status ENUM('pending','active','blocked') NOT NULL DEFAULT 'pending',
    	isAdmin TINYINT(1) NOT NULL DEFAULT 0,
    	isEnabled TINYINT(1) NOT NULL DEFAULT 1,
    	otherData LONGTEXT NOT NULL,
    	lastLogin DATETIME NULL DEFAULT NULL,
    	resetToken CHAR(64) NULL DEFAULT NULL,
    	resetRequestedAt DATETIME NULL DEFAULT NULL,
    	verifyToken CHAR(64) NULL DEFAULT NULL,
    	unsubscribeToken CHAR(64) NULL DEFAULT NULL,
    	failedLoginCount INT UNSIGNED NOT NULL DEFAULT 0,
    	lockedUntil DATETIME NULL DEFAULT NULL,
    	createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    	updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    	deletedAt DATETIME NULL DEFAULT NULL,
    	PRIMARY KEY  (id),
    	UNIQUE KEY uq_zgUsers_email (email),
    	KEY idx_zgUsers_status (status),
    	KEY idx_zgUsers_enabled (isEnabled),
    	KEY idx_zgUsers_lastLogin (lastLogin),
    	KEY idx_zgUsers_emailVerifiedAt (emailVerifiedAt)
    ) $charsetCollate;";

		$sqlEvents = "CREATE TABLE {$prefix}zgEvents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        date DATE NOT NULL,
		formJson LONGTEXT NOT NULL,
        sectionsJson LONGTEXT NOT NULL,
        rulesJson LONGTEXT NOT NULL,
        PRIMARY KEY (id)
    ) $charsetCollate;";

		$sqlReservations = "CREATE TABLE {$prefix}zgReservations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        userEmail VARCHAR(255) NOT NULL,
        eventId BIGINT UNSIGNED NOT NULL,
        sectionId VARCHAR(255) NOT NULL,
        status ENUM('confirmed','unpaid','waiting_list') NOT NULL,
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

		add_option ('zgDbVersion', ZENTRYGATE_VERSION_DB);
	}
}

