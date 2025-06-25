<?php


/**
 * admin/events.php
 * Gestión de eventos para ZentryGate
 *
 * Estructura JSON en la tabla wp_zgEvents, campo sectionsJson:
 * [
 *   {
 *     "id": "sec_1612345678",  // identificador interno único (oculto en interfaz)
 *     "label": "Day 1 - Morning", // nombre visible de la sección
 *     "capacity": 50,              // aforo máximo (0 = indefinido)
 *     "price": 20.00,              // precio en euros
 *     "isHidden": false            // indicador de sección oculta
 *   },
 *   ...
 * ]
 *
 * - Crear, editar y eliminar eventos (nombre, fecha)
 * - Crear, editar y listar secciones con atributos: label, capacity, isHidden, price
 * - Crear y listar reglas (sin precio)
 * - Cada handler devuelve true si procesa una acción, false en caso contrario
 */

/**
 * Renderiza la página de Eventos y delega acciones según 'action'.
 */
function zg_render_events_page ()
{
	echo '<div class="wrap">';
	$action = $_GET ['action'] ?? '';
	$eventId = intval ($_GET ['eventId'] ?? 0);
	$executed = false;

	switch ($action)
	{
		case 'editevent':
			if (! zg_handle_edit_event_action ())
			{
				$executed = true;
				echo '<h2>Editar Evento</h2>';
				zg_render_edit_event_page ($eventId);
			}
			break;

		case 'detail':
			if (! zg_handle_detail_event_actions ())
			{
				$executed = true;
				echo '<h2>ZentryGate - Detalle de Evento</h2>';
				zg_render_event_detail ($eventId);
			}
			break;

		default:
			if (zg_handle_general_event_actions ())
			{
				// this is the default action, so we will show anyway the list of events
				// $executed = true;
			}
	}

	if (! $executed)
	{
		echo '<h2>ZentryGate - Gestión de Eventos</h2>';
		zg_render_create_event_form ();
		zg_list_created_events ();
	}

	echo '</div>';
}


/**
 * Crea o elimina eventos.
 *
 * @return bool True si procesó una acción, false en caso contrario.
 */
function zg_handle_general_event_actions ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$capacityTable = $wpdb->prefix . 'zgCapacity';
	$reservationsTable = $wpdb->prefix . 'zgReservations';
	$handled = false;

	if (isset ($_POST ['zg_create_event']))
	{
		$wpdb->insert ($eventsTable, [ 'name' => sanitize_text_field ($_POST ['eventName']), 'date' => sanitize_text_field ($_POST ['eventDate']), 'sectionsJson' => '[]', 'rulesJson' => '[]']);
		echo '<div class="notice notice-success"><p>Evento creado correctamente.</p></div>';
		$handled = true;
	}

	if (isset ($_POST ['zg_delete_event']))
	{
		$eventId = intval ($_POST ['eventId']);

		// 1) Eliminar aforos de wp_zgCapacity
		$wpdb->delete ($capacityTable, [ 'eventId' => $eventId], [ '%d']);

		// 2)Eliminar reservas de wp_zgReservations
		$wpdb->delete ($reservationsTable, [ 'eventId' => $eventId], [ '%d']);

		// 3) Eliminar el propio evento
		$wpdb->delete ($eventsTable, [ 'id' => $eventId], [ '%d']);
		echo '<div class="notice notice-success"><p>Evento eliminado.</p></div>';
		$handled = true;
	}

	return $handled;
}


/**
 * Edita un evento.
 *
 * @return bool True si procesó la edición, false en caso contrario.
 */
function zg_handle_edit_event_action ()
{
	global $wpdb;
	$eventsTable = "{$wpdb->prefix}zgEvents";
	if (isset ($_POST ['zg_edit_event']))
	{
		$eventId = intval ($_POST ['eventId']);
		$wpdb->update ($eventsTable, [ 'name' => sanitize_text_field ($_POST ['eventName']), 'date' => sanitize_text_field ($_POST ['eventDate'])], [ 'id' => $eventId]);
		echo '<div class="notice notice-success"><p>Evento actualizado correctamente.</p></div>';
		return true;
	}
	return false;
}


// ------------------------------------------------
// Vistas (formularios y listados)
// ------------------------------------------------

/**
 * Formulario para crear un nuevo evento.
 */
function zg_render_create_event_form ()
{
	?>
    <h3>Crear Evento</h3>
    <form method="post" style="margin-bottom:20px;">
        <input type="text" name="eventName" placeholder="Nombre del evento" required>
        <input type="date" name="eventDate" required>
        <button type="submit" name="zg_create_event" class="button button-primary">➕ Crear</button>
    </form>
    <?php
}


/**
 * Listado de los eventos creados.
 */
function zg_list_created_events ()
{
	global $wpdb;
	$events = $wpdb->get_results ("SELECT * FROM {$wpdb->prefix}zgEvents ORDER BY date DESC");
	?>
    <h3>Eventos Creados</h3>
    <table class="widefat fixed striped">
        <thead><tr><th>Nombre</th><th>Fecha</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php

	foreach ($events as $e)
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

		echo admin_url ('admin.php?page=zentrygate_events&action=detail&eventId=' . $e->id);
		?>" class="button" title="Gestionar">🔧</a>
                    <a href="<?php

		echo admin_url ('admin.php?page=zentrygate_events&action=editevent&eventId=' . $e->id);
		?>" class="button" title="Editar">🖉</a>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="eventId" value="<?php

		echo esc_attr ($e->id);
		?>">
                        <button type="submit" name="zg_delete_event" class="button" title="Eliminar" onclick="return confirm('¿Eliminar evento?');">🗑</button>
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
 * Formulario para editar un evento existente.
 */
function zg_render_edit_event_page ($eventId)
{
	global $wpdb;
	$event = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
	if (! $event) return;
	?>
    <h3>Editar Evento</h3>
    <form method="post" style="margin-bottom:20px;">
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

	echo admin_url ('admin.php?page=zentrygate_events');
	?>" class="button">✖️ Cancelar</a>
    </form>
    <?php
}

