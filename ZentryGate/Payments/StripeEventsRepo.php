<?php

namespace ZentryGate\Payments;

class StripeEventsRepo
{


	public static function table (): string
	{
		global $wpdb;
		return $wpdb->prefix . 'zgStripeEvents';
	}


	/**
	 * Intenta registrar el evento.
	 * Si ya existe, incrementa attempts y devuelve fila existente.
	 * Devuelve array con claves: is_new(bool), row(object|null)
	 */
	public static function registerOrTouch (string $eventId, string $type, array $payloadArr, ?int $stripeCreatedTs = null): array
	{
		global $wpdb;
		$table = self::table ();
		$now = current_time ('mysql');
		$payloadJson = wp_json_encode ($payloadArr, JSON_UNESCAPED_UNICODE);

		// Intento atómico: insert ignora duplicados por UNIQUE(eventId)
		$inserted = $wpdb->query ($wpdb->prepare ("INSERT IGNORE INTO {$table} (eventId, type, stripeCreated, receivedAt, status, payload, attempts)
             VALUES (%s, %s, %s, %s, 'received', %s, 1)", $eventId, $type, $stripeCreatedTs ? gmdate ('Y-m-d H:i:s', $stripeCreatedTs) : null, $now, $payloadJson));

		if ($inserted === 1)
		{
			// Insert nuevo
			$row = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$table} WHERE eventId=%s", $eventId));
			return [ 'is_new' => true, 'row' => $row];
		}

		// Ya existía: incrementamos attempts y actualizamos payload por si llegó “más fresco”
		$wpdb->query ($wpdb->prepare ("UPDATE {$table}
             SET attempts = attempts + 1,
                 payload = %s,
                 receivedAt = %s
             WHERE eventId=%s", $payloadJson, $now, $eventId));
		$row = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$table} WHERE eventId=%s", $eventId));
		return [ 'is_new' => false, 'row' => $row];
	}


	public static function markProcessed (string $eventId, int $httpStatusSent = 200): void
	{
		global $wpdb;
		$table = self::table ();
		$now = current_time ('mysql');
		$wpdb->update ($table, [ 'status' => 'processed', 'processedAt' => $now, 'httpStatusSent' => $httpStatusSent, 'lastError' => null], [ 'eventId' => $eventId], [ '%s', '%s', '%d', '%s'], [ '%s']);
	}


	public static function markSkipped (string $eventId, int $httpStatusSent = 200, ?string $why = null): void
	{
		global $wpdb;
		$table = self::table ();
		$now = current_time ('mysql');
		$wpdb->update ($table, [ 'status' => 'skipped', 'processedAt' => $now, 'httpStatusSent' => $httpStatusSent, 'lastError' => $why], [ 'eventId' => $eventId], [ '%s', '%s', '%d', '%s'], [ '%s']);
	}


	public static function markFailed (string $eventId, int $httpStatusSent = 200, string $error): void
	{
		global $wpdb;
		$table = self::table ();
		$now = current_time ('mysql');
		$wpdb->update ($table, [ 'status' => 'failed', 'processedAt' => $now, 'httpStatusSent' => $httpStatusSent, 'lastError' => $error], [ 'eventId' => $eventId], [ '%s', '%s', '%d', '%s'], [ '%s']);
	}


	public static function isProcessed (string $eventId): bool
	{
		global $wpdb;
		$table = self::table ();
		$status = $wpdb->get_var ($wpdb->prepare ("SELECT status FROM {$table} WHERE eventId=%s", $eventId));
		return $status === 'processed' || $status === 'skipped'; // “ya no hay nada que hacer”
	}
}
