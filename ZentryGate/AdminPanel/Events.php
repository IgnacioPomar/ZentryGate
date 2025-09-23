<?php

namespace ZentryGate\AdminPanel;

class Events
{


	/**
	 * Punto de entrada estático para el callback del menú
	 */
	public static function render (): void
	{
		$self = new self ();
		$self->dispatch ();
	}


	/**
	 * Router principal: lee action/eventId, procesa y pinta
	 */
	protected function dispatch (): void
	{
		if (! current_user_can ('manage_options'))
		{
			wp_die (__ ('No tienes permisos suficientes.', 'zentrygate'));
		}

		echo '<div class="wrap">';

		$action = isset ($_GET ['action']) ? sanitize_key ($_GET ['action']) : '';
		$eventId = isset ($_GET ['eventId']) ? intval ($_GET ['eventId']) : 0;
		$executed = false;

		switch ($action)
		{
			case 'editevent':
				if (! $this->handleEditEventAction ())
				{
					$executed = true;
					echo '<h2>Editar Evento</h2>';
					$this->renderEditEventPage ($eventId);
				}
				break;

			case 'detail':
				$this->renderEventDetailsPage ($eventId);
				$executed = true;
				break;

			default:
				if ($this->handleGeneralEventActions ())
				{
					// aun así mostraremos el listado más abajo
				}
		}

		if (! $executed)
		{
			echo '<h2>ZentryGate - Gestión de Eventos</h2>';
			$this->renderCreateEventForm ();
			$this->listCreatedEvents ();
		}

		echo '</div>';
	}


	/**
	 * Acciones generales: crear / eliminar
	 */
	protected function handleGeneralEventActions (): bool
	{
		if ($_SERVER ['REQUEST_METHOD'] !== 'POST')
		{
			return false;
		}

		// Nonce compartido para este formulario
		if (! isset ($_POST ['_zg_nonce']) || ! wp_verify_nonce ($_POST ['_zg_nonce'], 'zg_events_nonce'))
		{
			return false;
		}

		global $wpdb;
		$tables = $this->tables ();
		$handled = false;

		if (isset ($_POST ['zg_create_event']))
		{
			$name = isset ($_POST ['eventName']) ? sanitize_text_field (wp_unslash ($_POST ['eventName'])) : '';
			$date = isset ($_POST ['eventDate']) ? sanitize_text_field (wp_unslash ($_POST ['eventDate'])) : '';

			$wpdb->insert ($tables ['events'], [ 'name' => $name, 'date' => $date, 'sectionsJson' => '[]', 'rulesJson' => '[]'], [ '%s', '%s', '%s', '%s']);

			echo '<div class="notice notice-success"><p>Evento creado correctamente.</p></div>';
			$handled = true;
		}

		if (isset ($_POST ['zg_delete_event']))
		{
			$eventId = isset ($_POST ['eventId']) ? intval ($_POST ['eventId']) : 0;

			// 1) Aforos
			$wpdb->delete ($tables ['capacity'], [ 'eventId' => $eventId], [ '%d']);
			// 2) Reservas
			$wpdb->delete ($tables ['reservations'], [ 'eventId' => $eventId], [ '%d']);
			// 3) Evento
			$wpdb->delete ($tables ['events'], [ 'id' => $eventId], [ '%d']);

			echo '<div class="notice notice-success"><p>Evento eliminado.</p></div>';
			$handled = true;
		}

		return $handled;
	}


	/**
	 * Editar un evento existente
	 */
	protected function handleEditEventAction (): bool
	{
		if ($_SERVER ['REQUEST_METHOD'] !== 'POST')
		{
			return false;
		}
		if (! isset ($_POST ['_zg_nonce']) || ! wp_verify_nonce ($_POST ['_zg_nonce'], 'zg_events_nonce'))
		{
			return false;
		}

		global $wpdb;
		$tables = $this->tables ();

		if (isset ($_POST ['zg_edit_event']))
		{
			$eventId = isset ($_POST ['eventId']) ? intval ($_POST ['eventId']) : 0;
			$name = isset ($_POST ['eventName']) ? sanitize_text_field (wp_unslash ($_POST ['eventName'])) : '';
			$date = isset ($_POST ['eventDate']) ? sanitize_text_field (wp_unslash ($_POST ['eventDate'])) : '';

			$wpdb->update ($tables ['events'], [ 'name' => $name, 'date' => $date], [ 'id' => $eventId], [ '%s', '%s'], [ '%d']);

			echo '<div class="notice notice-success"><p>Evento actualizado correctamente.</p></div>';
			return true;
		}

		return false;
	}


	// ---------------------------
	// Vistas
	// ---------------------------

	/**
	 * Formulario crear evento
	 */
	protected function renderCreateEventForm (): void
	{
		$nonce = wp_create_nonce ('zg_events_nonce');
		?>
        <h3>Crear Evento</h3>
        <form method="post" style="margin-bottom:20px;">
            <input type="hidden" name="_zg_nonce" value="<?php

		echo esc_attr ($nonce);
		?>">
            <input type="text" name="eventName" placeholder="Nombre del evento" required>
            <input type="date" name="eventDate" required>
            <button type="submit" name="zg_create_event" class="button button-primary">➕ Crear</button>
        </form>
        <?php
	}


	/**
	 * Listado de eventos creados
	 */
	protected function listCreatedEvents (): void
	{
		global $wpdb;
		$events = $wpdb->get_results ("SELECT * FROM {$wpdb->prefix}zgEvents ORDER BY date DESC");
		?>
        <h3>Eventos Creados</h3>
        <table class="widefat fixed striped">
            <thead><tr><th>Nombre</th><th>Fecha</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php

		foreach ((array) $events as $e)
		:
			?>
                <tr>
                    <td><?php

			echo esc_html ($e->name);
			?></td>
                    <td><?php

			echo esc_html ($e->date);
			?></td>
                    <td>
                        <a href="<?php
			echo esc_url (add_query_arg ([ 'page' => 'zentrygate_events', 'action' => 'detail', 'eventId' => (int) $e->id], admin_url ('admin.php')));
			?>" class="button" title="Gestionar">🔧</a>

                        <a href="<?php
			echo esc_url (add_query_arg ([ 'page' => 'zentrygate_events', 'action' => 'editevent', 'eventId' => (int) $e->id], admin_url ('admin.php')));
			?>" class="button" title="Editar">🖉</a>

                        <form method="post" style="display:inline;">
                            <input type="hidden" name="_zg_nonce" value="<?php

			echo esc_attr (wp_create_nonce ('zg_events_nonce'));
			?>">
                            <input type="hidden" name="eventId" value="<?php

			echo esc_attr ($e->id);
			?>">
                            <button type="submit" name="zg_delete_event" class="button" title="Eliminar"
                                    onclick="return confirm('¿Eliminar evento?');">🗑
                            </button>
                        </form>
                    </td>
                </tr>
            <?php
		endforeach
		;
		?>
            </tbody>
        </table>
        <?php
	}


	/**
	 * Formulario editar evento
	 */
	protected function renderEditEventPage (int $eventId): void
	{
		if ($eventId <= 0) return;

		global $wpdb;
		$event = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
		if (! $event) return;

		$nonce = wp_create_nonce ('zg_events_nonce');
		?>
        <h3>Editar Evento</h3>
        <form method="post" style="margin-bottom:20px;">
            <input type="hidden" name="_zg_nonce" value="<?php

		echo esc_attr ($nonce);
		?>">
            <input type="hidden" name="eventId" value="<?php

		echo esc_attr ($eventId);
		?>">
            <input type="text" name="eventName" value="<?php

		echo esc_attr ($event->name);
		?>" required>
            <input type="date" name="eventDate" value="<?php

		echo esc_attr ($event->date);
		?>" required>
            <button type="submit" name="zg_edit_event" class="button button-primary">💾 Guardar</button>
            <a href="<?php
		echo esc_url (add_query_arg ([ 'page' => 'zentrygate_events'], admin_url ('admin.php')));
		?>" class="button">✖️ Cancelar</a>
        </form>
        <?php
	}


	/**
	 * Página de detalle del evento (stub para que no rompa)
	 */
	protected function renderEventDetailsPage (int $eventId): void
	{
		echo '<h2>Detalle del evento</h2>';
		if ($eventId <= 0)
		{
			echo '<p>No se ha indicado un evento.</p>';
			return;
		}
		echo '<p>Aquí iría la gestión detallada (aforos, secciones, reglas, reservas...).</p>';
	}


	// ---------------------------
	// Utilidades
	// ---------------------------
	protected function tables (): array
	{
		global $wpdb;
		return [ 'events' => "{$wpdb->prefix}zgEvents", 'capacity' => "{$wpdb->prefix}zgCapacity", 'reservations' => "{$wpdb->prefix}zgReservations"];
	}
}

