<?php

namespace ZentryGate\Payments;

class CapacityRepo
{


	/**
	 * Decrementa usedCapacity de una sección sin bajar de 0.
	 *
	 * No es idempotente por sí sola: quien la invoque debe asegurarse de llamarla
	 * como mucho una vez por plaza liberada (p. ej. comprobando el status anterior
	 * de la reserva dentro de una transacción con SELECT ... FOR UPDATE).
	 */
	public static function release (int $eventId, string $sectionId): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgCapacity';

		$wpdb->query ($wpdb->prepare ("UPDATE {$table}
                SET usedCapacity = GREATEST(usedCapacity - 1, 0)
              WHERE eventId=%d AND sectionId=%s", $eventId, $sectionId));
	}
}
