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
					echo '<div class="zg-admin-column">';
					$this->renderEditEventPage ($eventId);
					echo '</div>';
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
			echo '<div class="zg-admin-column">';
			$this->renderCreateEventForm ();
			$this->renderDuplicateEventForm ();
			$this->renderImportEventForm ();
			$this->listCreatedEvents ();
			echo '</div>';
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

		if (isset ($_POST ['zg_duplicate_event']))
		{
			$sourceId = isset ($_POST ['sourceEventId']) ? intval ($_POST ['sourceEventId']) : 0;
			$name = isset ($_POST ['eventName']) ? sanitize_text_field (wp_unslash ($_POST ['eventName'])) : '';
			$date = isset ($_POST ['eventDate']) ? sanitize_text_field (wp_unslash ($_POST ['eventDate'])) : '';

			$source = $wpdb->get_row ($wpdb->prepare ("SELECT formJson, sectionsJson, rulesJson FROM {$tables['events']} WHERE id = %d", $sourceId));

			if (! $source || $name === '' || $date === '')
			{
				echo '<div class="notice notice-error"><p>No se pudo duplicar el evento: datos incompletos.</p></div>';
			}
			else
			{
				$wpdb->insert ($tables ['events'], [ 'name' => $name, 'date' => $date, 'formJson' => $source->formJson, 'sectionsJson' => $source->sectionsJson, 'rulesJson' => $source->rulesJson, 'closed' => 0], [ '%s', '%s', '%s', '%s', '%s', '%d']);

				$newEventId = (int) $wpdb->insert_id;
				$this->seedCapacityFromSections ($newEventId, $source->sectionsJson);

				echo '<div class="notice notice-success"><p>Evento duplicado correctamente.</p></div>';
			}
			$handled = true;
		}

		if (isset ($_POST ['zg_toggle_close_event']))
		{
			$eventId = isset ($_POST ['eventId']) ? intval ($_POST ['eventId']) : 0;
			$current = (int) $wpdb->get_var ($wpdb->prepare ("SELECT closed FROM {$tables['events']} WHERE id = %d", $eventId));
			$wpdb->update ($tables ['events'], [ 'closed' => $current ? 0 : 1], [ 'id' => $eventId], [ '%d'], [ '%d']);

			echo '<div class="notice notice-success"><p>' . ($current ? 'Evento reabierto.' : 'Evento cerrado.') . '</p></div>';
			$handled = true;
		}

		if (isset ($_POST ['zg_import_event']))
		{
			if (! isset ($_FILES ['zg_import_file']) || $_FILES ['zg_import_file'] ['error'] !== UPLOAD_ERR_OK)
			{
				echo '<div class="notice notice-error"><p>No se ha podido leer el fichero.</p></div>';
			}
			else
			{
				$raw = file_get_contents ($_FILES ['zg_import_file'] ['tmp_name']);
				$data = json_decode ((string) $raw, true);

				if (! is_array ($data) || empty ($data ['name']) || empty ($data ['date']))
				{
					echo '<div class="notice notice-error"><p>El fichero no tiene un formato de evento válido.</p></div>';
				}
				else
				{
					$name = sanitize_text_field ($data ['name']);
					$date = sanitize_text_field ($data ['date']);
					$dateObj = \DateTime::createFromFormat ('Y-m-d', $date);

					if (! $dateObj || $dateObj->format ('Y-m-d') !== $date)
					{
						echo '<div class="notice notice-error"><p>Fecha del evento importado no válida.</p></div>';
					}
					else
					{
						$formJson = wp_json_encode ($data ['formJson'] ?? [ ]);
						$sectionsJson = wp_json_encode ($data ['sectionsJson'] ?? [ ]);
						$rulesJson = wp_json_encode ($data ['rulesJson'] ?? [ ]);
						$closed = ! empty ($data ['closed']) ? 1 : 0;

						$wpdb->insert ($tables ['events'], [ 'name' => $name, 'date' => $date, 'formJson' => $formJson, 'sectionsJson' => $sectionsJson, 'rulesJson' => $rulesJson, 'closed' => $closed], [ '%s', '%s', '%s', '%s', '%s', '%d']);

						$newEventId = (int) $wpdb->insert_id;
						$this->seedCapacityFromSections ($newEventId, $sectionsJson);

						echo '<div class="notice notice-success"><p>Evento importado correctamente.</p></div>';
					}
				}
			}
			$handled = true;
		}

		return $handled;
	}


	/**
	 * Crea filas en zgCapacity para cada sección de un sectionsJson (evento nuevo, sin aforo usado).
	 */
	protected function seedCapacityFromSections (int $eventId, string $sectionsJson): void
	{
		global $wpdb;
		$tables = $this->tables ();
		$sections = json_decode ($sectionsJson, true) ?: [ ];

		foreach ($sections as $section)
		{
			$sectionId = isset ($section ['id']) ? (string) $section ['id'] : '';
			if ($sectionId === '')
			{
				continue;
			}
			$capacity = isset ($section ['capacity']) ? intval ($section ['capacity']) : 0;

			$wpdb->insert ($tables ['capacity'], [ 'eventId' => $eventId, 'sectionId' => $sectionId, 'maxCapacity' => $capacity, 'usedCapacity' => 0], [ '%d', '%s', '%d', '%d']);
		}
	}


	/**
	 * Exportar la definición completa de un evento como fichero JSON descargable.
	 */
	public static function exportEvent (): void
	{
		if (! current_user_can ('manage_options'))
		{
			wp_die (__ ('No tienes permisos suficientes.', 'zentrygate'));
		}

		$eventId = isset ($_GET ['eventId']) ? intval ($_GET ['eventId']) : 0;
		check_admin_referer ('zg_export_event_' . $eventId, '_zg_export_nonce');

		global $wpdb;
		$table = $wpdb->prefix . 'zgEvents';
		$event = $wpdb->get_row ($wpdb->prepare ("SELECT name, date, formJson, sectionsJson, rulesJson, closed FROM {$table} WHERE id = %d", $eventId), ARRAY_A);

		if (! $event)
		{
			wp_die (__ ('Evento no encontrado.', 'zentrygate'));
		}

		$payload = [ 'zgExportVersion' => 1, 'name' => $event ['name'], 'date' => $event ['date'], 'formJson' => json_decode ($event ['formJson'] ?: '[]', true), 'sectionsJson' => json_decode ($event ['sectionsJson'] ?: '[]', true), 'rulesJson' => json_decode ($event ['rulesJson'] ?: '[]', true), 'closed' => (int) $event ['closed']];

		$filename = 'zentrygate-event-' . $eventId . '-' . sanitize_title ($event ['name']) . '.json';

		nocache_headers ();
		header ('Content-Type: application/json; charset=utf-8');
		header ('Content-Disposition: attachment; filename="' . $filename . '"');
		echo wp_json_encode ($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		exit ();
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
        <div class="postbox">
        <h2 class="hndle"><span>Crear Evento</span></h2>
        <div class="inside">
        <form method="post">
            <input type="hidden" name="_zg_nonce" value="<?php

		echo esc_attr ($nonce);
		?>">
            <input type="text" name="eventName" placeholder="Nombre del evento" required>
            <input type="date" name="eventDate" required>
            <button type="submit" name="zg_create_event" class="button button-primary">➕ Crear</button>
        </form>
        </div>
        </div>
        <?php
	}


	/**
	 * Formulario duplicar evento existente
	 */
	protected function renderDuplicateEventForm (): void
	{
		$events = $this->getAllEventsForSelect ();
		if (empty ($events))
		{
			return;
		}

		$nonce = wp_create_nonce ('zg_events_nonce');
		?>
        <div class="postbox">
        <h2 class="hndle"><span>Duplicar Evento</span></h2>
        <div class="inside">
        <form method="post">
            <input type="hidden" name="_zg_nonce" value="<?php

		echo esc_attr ($nonce);
		?>">
            <select name="sourceEventId" required>
                <option value="">— Selecciona evento origen —</option>
                <?php

		foreach ($events as $ev)
		:
			?>
                    <option value="<?php

			echo esc_attr ($ev->id);
			?>"><?php

			echo esc_html ($ev->name . ' (' . $ev->date . ')');
			?></option>
                <?php
		endforeach
		;
		?>
            </select>
            <input type="text" name="eventName" placeholder="Nombre del nuevo evento" required>
            <input type="date" name="eventDate" required>
            <button type="submit" name="zg_duplicate_event" class="button button-primary">📄 Duplicar</button>
        </form>
        </div>
        </div>
        <?php
	}


	/**
	 * Formulario importar evento desde fichero JSON
	 */
	protected function renderImportEventForm (): void
	{
		$nonce = wp_create_nonce ('zg_events_nonce');
		?>
        <div class="postbox">
        <h2 class="hndle"><span>Importar Evento</span></h2>
        <div class="inside">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_zg_nonce" value="<?php

		echo esc_attr ($nonce);
		?>">
            <input type="file" name="zg_import_file" accept="application/json" required>
            <button type="submit" name="zg_import_event" class="button button-primary">⬆️ Importar</button>
        </form>
        </div>
        </div>
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
        <div class="postbox">
        <h2 class="hndle"><span>Eventos Creados</span></h2>
        <div class="inside">
        <table class="widefat fixed striped">
            <thead><tr><th>Nombre</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead>
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
                    <td><?php

			echo $e->closed ? '🔒 Cerrado' : '🟢 Abierto';
			?></td>
                    <td>
                        <a href="<?php
			echo esc_url (add_query_arg ([ 'page' => 'zentrygate_events', 'action' => 'detail', 'eventId' => (int) $e->id], admin_url ('admin.php')));
			?>" class="button" title="Gestionar">🔧</a>

                        <a href="<?php
			echo esc_url (add_query_arg ([ 'page' => 'zentrygate_events', 'action' => 'editevent', 'eventId' => (int) $e->id], admin_url ('admin.php')));
			?>" class="button" title="Editar">🖉</a>

                        <a href="<?php
			echo esc_url (wp_nonce_url (add_query_arg ([ 'action' => 'zg_export_event', 'eventId' => (int) $e->id], admin_url ('admin-post.php')), 'zg_export_event_' . $e->id, '_zg_export_nonce'));
			?>" class="button" title="Exportar">⬇️</a>

                        <form method="post" style="display:inline;">
                            <input type="hidden" name="_zg_nonce" value="<?php

			echo esc_attr (wp_create_nonce ('zg_events_nonce'));
			?>">
                            <input type="hidden" name="eventId" value="<?php

			echo esc_attr ($e->id);
			?>">
                            <button type="submit" name="zg_toggle_close_event" class="button" title="<?php

			echo $e->closed ? 'Reabrir' : 'Cerrar';
			?>"><?php

			echo $e->closed ? '🔓' : '🔒';
			?></button>
                        </form>

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
        </div>
        </div>
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
        <div class="postbox">
        <h2 class="hndle"><span>Editar Evento</span></h2>
        <div class="inside">
        <form method="post">
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
        </div>
        </div>
        <?php
	}


	/**
	 * Página de detalle del evento (stub para que no rompa)
	 */
	protected function renderEventDetailsPage (int $eventId): void
	{
		// TODO: migrar a POO
		zg_render_event_details_page ($eventId);
	}


	// ---------------------------
	// Utilidades
	// ---------------------------
	protected function tables (): array
	{
		global $wpdb;
		return [ 'events' => "{$wpdb->prefix}zgEvents", 'capacity' => "{$wpdb->prefix}zgCapacity", 'reservations' => "{$wpdb->prefix}zgReservations"];
	}


	/**
	 * Lista de eventos (id, name, date) para poblar el combo de duplicar
	 */
	protected function getAllEventsForSelect (): array
	{
		global $wpdb;
		$tables = $this->tables ();
		return (array) $wpdb->get_results ("SELECT id, name, date FROM {$tables['events']} ORDER BY date DESC");
	}
}

