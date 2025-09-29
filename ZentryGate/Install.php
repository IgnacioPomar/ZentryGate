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

		// -------------------------------
		// Temporal: borrar tablas que no encajan en el nuevo esquema y han de ser recreadas
		$wpdb->query ("DROP TABLE IF EXISTS {$prefix}zgReservations;");
		// -------------------------------

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

		// YAGNI: quizás sería más interesante tener un UUID con la reserva, para usarlo en URLs y referencias externas
		$sqlReservations = "CREATE TABLE {$prefix}zgReservations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Identificador único de la reserva',
            userId BIGINT UNSIGNED NOT NULL COMMENT 'Referencia al usuario (zgUsers.id)',
            eventId BIGINT UNSIGNED NOT NULL COMMENT 'Referencia al evento',
            sectionId VARCHAR(64) NOT NULL COMMENT 'Sección del evento (o FK si existe tabla de secciones)',
            
            status ENUM('held','pending_payment','confirmed','waiting_list','cancelled','expired') NOT NULL
                COMMENT 'Estado lógico de la reserva (no confundir con el pago)',
            paymentStatus ENUM('none','requires_action','processing','succeeded','failed','refunded','partially_refunded','canceled')
                NOT NULL DEFAULT 'none' COMMENT 'Estado del pago en pasarela',
                
            amountCents INT UNSIGNED NULL COMMENT 'Importe en céntimos',
            currency CHAR(3) NULL COMMENT 'Divisa ISO, p.ej. EUR',
            paymentIntentId VARCHAR(255) NULL COMMENT 'PaymentIntent ID en Stripe',
            latestChargeId VARCHAR(255) NULL COMMENT 'Último cargo asociado',
            refundedCents INT UNSIGNED NULL COMMENT 'Importe reembolsado en céntimos',
            receiptUrl VARCHAR(512) NULL COMMENT 'URL del recibo de Stripe',
            stripePayload JSON NULL COMMENT 'Payload completo de Stripe (usar LONGTEXT si no hay soporte JSON)',
            
            waitlistPosition INT UNSIGNED NULL COMMENT 'Posición en la lista de espera (FIFO)',
            expiresAt DATETIME NULL COMMENT 'Fecha límite del hold o del intento de pago',
            confirmedAt DATETIME NULL COMMENT 'Momento de confirmación',
            cancelledAt DATETIME NULL COMMENT 'Momento de cancelación',
            checkedInAt DATETIME NULL COMMENT 'Momento de marcar asistencia',
            attendanceStatus ENUM('none','checked_in','no_show') NOT NULL DEFAULT 'none'
                COMMENT 'Estado de asistencia en el evento',
                
            createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
            updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                COMMENT 'Última actualización',
                
            PRIMARY KEY (id),
            UNIQUE KEY uq_reservation (eventId, sectionId, userId),
            KEY idx_event_section (eventId, sectionId, status, createdAt),
            KEY idx_user_event (userId, eventId)
        ) $charsetCollate;";

		$sqlCapacity = "CREATE TABLE {$prefix}zgCapacity (
        eventId BIGINT UNSIGNED NOT NULL,
        sectionId VARCHAR(255) NOT NULL,
        maxCapacity INT NOT NULL,
		usedCapacity INT NOT NULL,
        PRIMARY KEY (eventId, sectionId)
    ) $charsetCollate;";

		$sqlStripeEvents = "CREATE TABLE {$prefix}zgStripeEvents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        eventId VARCHAR(255) NOT NULL,
        type VARCHAR(191) NOT NULL,
        stripeCreated TIMESTAMP NULL DEFAULT NULL COMMENT 'Fecha del evento en Stripe (event->created)',
        receivedAt DATETIME NOT NULL COMMENT 'Fecha local WP al recibir',
        processedAt DATETIME NULL DEFAULT NULL COMMENT 'Fecha local WP al finalizar handler',
        status VARCHAR(32) NOT NULL DEFAULT 'received',
        httpStatusSent SMALLINT UNSIGNED NULL DEFAULT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 1,
        lastError TEXT NULL,
        payload LONGTEXT NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_event (eventId),
        KEY idx_type (type),
        KEY idx_status (status),
        KEY idx_received (receivedAt)
    ) {$charsetCollate};";

		dbDelta ($sqlUsers);
		dbDelta ($sqlEvents);
		dbDelta ($sqlReservations);
		dbDelta ($sqlCapacity);
		dbDelta ($sqlStripeEvents);

		add_option ('zgDbVersion', ZENTRYGATE_VERSION_DB);
	}
}

