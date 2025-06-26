<?php


/**
 * eventDetails.php
 *
 * Gestión de secciones y reglas de un evento en el plugin ZentryGate.
 */

/**
 * Renderiza toda la página de detalle de evento (dispatch por subaction).
 *
 * @param int $eventId
 */
function zg_render_event_details_page ($eventId)
{
	global $wpdb;
	$table = $wpdb->prefix . 'zgEvents';

	// Obtener nombre y fecha
	$event = $wpdb->get_row ($wpdb->prepare ("SELECT name, date FROM {$table} WHERE id = %d", $eventId));
	$formatted_date = date_i18n (get_option ('date_format'), strtotime ($event->date));
	printf ('<h2>ZentryGate – Detalle de Evento – %s (%s)</h2>', esc_html ($event->name), esc_html ($formatted_date));

	$subaction = sanitize_text_field ($_GET ['subaction'] ?? '');
	$executed = false;

	switch ($subaction)
	{

		case 'editsection':
			if (! zg_handle_detail_event_editsection ())
			{
				$sectionId = sanitize_text_field ($_GET ['sectionId'] ?? '');
				zg_render_edit_section_form ($eventId, $sectionId);
				$executed = true;
			}
			break;

		case 'deletesection':
			zg_handle_detail_event_deletesection ();
			break;

		// ------------------------------------------------------------------------

		case 'addrule':
			if (! zg_handle_detail_event_addrule ())
			{
				zg_render_add_rule_form ($eventId);
				$executed = true;
			}
			break;

		case 'editrule':
			if (! zg_handle_detail_event_editrule ())
			{
				$ruleIndex = intval ($_GET ['ruleIndex'] ?? - 1);
				zg_render_edit_rule_form ($eventId, $ruleIndex);
				$executed = true;
			}
			break;

		case 'deleterule':
			zg_handle_detail_event_deleterule ();
			break;

		// ------------------------------------------------------------------------
		default:
			// If we dont have a subaction, the only possible action is to add a new section
			zg_handle_detail_event_addsection ();
			break;
	}

	if (! $executed)
	{
		// Si no se ha ejecutado ninguna acción, mostramos el detalle del evento
		zg_render_event_detail ($eventId);
	}
}


/**
 * Add a new section to the event.
 *
 * @return bool True if the section was added, false otherwise.
 */
function zg_handle_detail_event_addsection ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$capTable = $wpdb->prefix . 'zgCapacity';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);
	$handled = false;

	if (isset ($_POST ['zg_add_section']))
	{
		$label = sanitize_text_field ($_POST ['sectionLabel']);
		$capacity = intval ($_POST ['sectionCapacity']);
		$price = floatval ($_POST ['sectionPrice']);
		$isHidden = isset ($_POST ['sectionHidden']);
		$sections = json_decode ($wpdb->get_var ($wpdb->prepare ("SELECT sectionsJson FROM {$eventsTable} WHERE id = %d", $eventId)), true) ?: [ ];

		$sectionId = (string) abs (crc32 (uniqid ()));
		$sections [] = [ 'id' => $sectionId, 'label' => $label, 'capacity' => $capacity, 'price' => $price, 'isHidden' => $isHidden];
		$wpdb->update ($eventsTable, [ 'sectionsJson' => wp_json_encode ($sections)], [ 'id' => $eventId], [ '%s'], [ '%d']);
		$wpdb->insert ($capTable, [ 'eventId' => $eventId, 'sectionId' => $sectionId, 'maxCapacity' => $capacity], [ '%d', '%s', '%d']);
		echo '<div class="notice notice-success"><p>Sección creada correctamente.</p></div>';
		$handled = true;
	}

	return $handled;
}


/**
 * Edit an existing section of the event.
 *
 * @return bool True if the section was edited, false otherwise.
 */
function zg_handle_detail_event_editsection ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$capTable = $wpdb->prefix . 'zgCapacity';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);
	$handled = false;

	if (isset ($_POST ['zg_edit_section']))
	{
		$sectionId = sanitize_text_field ($_POST ['sectionId']);
		$label = sanitize_text_field ($_POST ['sectionLabel']);
		$capacity = intval ($_POST ['sectionCapacity']);
		$price = floatval ($_POST ['sectionPrice']);
		$isHidden = isset ($_POST ['sectionHidden']);
		$sections = json_decode ($wpdb->get_var ($wpdb->prepare ("SELECT sectionsJson FROM {$eventsTable} WHERE id = %d", $eventId)), true) ?: [ ];

		foreach ($sections as &$sec)
		{
			if ($sec ['id'] === $sectionId)
			{
				$sec ['label'] = $label;
				$sec ['capacity'] = $capacity;
				$sec ['price'] = $price;
				$sec ['isHidden'] = $isHidden;
				break;
			}
		}
		unset ($sec);
		$wpdb->update ($eventsTable, [ 'sectionsJson' => wp_json_encode ($sections)], [ 'id' => $eventId], [ '%s'], [ '%d']);
		$wpdb->update ($capTable, [ 'maxCapacity' => $capacity], [ 'eventId' => $eventId, 'sectionId' => $sectionId], [ '%d'], [ '%d', '%s']);
		echo '<div class="notice notice-success"><p>Sección actualizada correctamente.</p></div>';
		$handled = true;
	}

	return $handled;
}


/**
 * Delete a section from the event.
 *
 * @return bool True if the section was deleted, false otherwise.
 */
function zg_handle_detail_event_deletesection ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$capTable = $wpdb->prefix . 'zgCapacity';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);
	$handled = false;

	// Identificador de la sección a eliminar
	$sectionId = sanitize_text_field ($_GET ['sectionId'] ?? '');

	// Obtener el JSON actual de secciones
	$sections = json_decode ($wpdb->get_var ($wpdb->prepare ("SELECT sectionsJson FROM {$eventsTable} WHERE id = %d", $eventId)), true) ?: [ ];

	// Filtrar la sección a eliminar
	$newSections = [ ];
	foreach ($sections as $section)
	{
		if ((string) $section ['id'] !== $sectionId)
		{
			$newSections [] = $section;
		}
	}

	// Si no había nada que eliminar, salimos
	if (count ($newSections) === count ($sections))
	{
		echo '<div class="notice notice-warning"><p>Sección no encontrada.</p></div>';
		return false;
	}

	// Actualizar el JSON de secciones en la tabla de eventos
	$updated = $wpdb->update ($eventsTable, [ 'sectionsJson' => wp_json_encode ($newSections)], [ 'id' => $eventId], [ '%s'], [ '%d']);

	// Eliminar la sección de la tabla de capacidad
	$deleted = $wpdb->delete ($capTable, [ 'eventId' => $eventId, 'sectionId' => $sectionId], [ '%d', '%s']);

	if (false === $updated || false === $deleted)
	{
		echo '<div class="notice notice-error"><p>Error al eliminar la sección.</p></div>';
	}
	else
	{
		echo '<div class="notice notice-success"><p>Sección eliminada correctamente.</p></div>';
		$handled = true;
	}

	return $handled;
}


/**
 * Delete a rule from the event.
 */
function zg_handle_detail_event_addrule ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);
	$handled = false;

	// Añadir regla
	if (isset ($_POST ['zg_add_rule']))
	{
		$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson, rulesJson FROM {$eventsTable} WHERE id = %d", $eventId));
		$sections = json_decode ($row->sectionsJson, true) ?: [ ];
		if (! empty ($sections))
		{
			$rules = json_decode ($row->rulesJson, true) ?: [ ];
			$rules [] = [ 'rule' => sanitize_text_field ($_POST ['ruleName']), 'cond' => array_map ('sanitize_text_field', (array) $_POST ['ruleConds']), 'optional' => isset ($_POST ['ruleOptional']), 'action' => sanitize_text_field ($_POST ['ruleAction'])];
			$wpdb->update ($eventsTable, [ 'rulesJson' => wp_json_encode ($rules)], [ 'id' => $eventId], [ '%s'], [ '%d']);
			echo '<div class="notice notice-success"><p>Regla creada correctamente.</p></div>';
			$handled = true;
		}
	}

	return $handled;
}


/**
 * Delete a rule from the event.
 */
function zg_handle_detail_event_editrule ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);
	$handled = false;

	if (isset ($_POST ['zg_edit_rule']))
	{
		$ruleIndex = intval ($_POST ['ruleIndex']);
		$row = $wpdb->get_row ($wpdb->prepare ("SELECT rulesJson FROM {$eventsTable} WHERE id = %d", $eventId));
		$rules = json_decode ($row->rulesJson, true) ?: [ ];
		if (isset ($rules [$ruleIndex]))
		{
			$rules [$ruleIndex] ['rule'] = sanitize_text_field ($_POST ['ruleName']);
			$rules [$ruleIndex] ['cond'] = array_map ('sanitize_text_field', (array) $_POST ['ruleConds']);
			$rules [$ruleIndex] ['optional'] = isset ($_POST ['ruleOptional']);
			$rules [$ruleIndex] ['action'] = sanitize_text_field ($_POST ['ruleAction']);
			$wpdb->update ($eventsTable, [ 'rulesJson' => wp_json_encode ($rules)], [ 'id' => $eventId], [ '%s'], [ '%d']);
			echo '<div class="notice notice-success"><p>Regla actualizada correctamente.</p></div>';
			$handled = true;
		}
	}

	return $handled;
}


// zg_handle_detail_event_editrule

/**
 * Delete a rule from the event.
 *
 * @return bool True if the rule was deleted, false otherwise.
 */
function zg_handle_detail_event_deleterule ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);
	$ruleIndex = intval ($_REQUEST ['ruleIndex'] ?? - 1);

	$handled = false;

	if (isset ($_REQUEST ['subaction']) && $_REQUEST ['subaction'] === 'deleterule' && isset ($_GET ['ruleIndex']))
	{
		$ruleIndex = intval ($_GET ['ruleIndex']);
		$row = $wpdb->get_row ($wpdb->prepare ("SELECT rulesJson FROM {$eventsTable} WHERE id = %d", $eventId));
		$rules = json_decode ($row->rulesJson, true) ?: [ ];
		if (isset ($rules [$ruleIndex]))
		{
			array_splice ($rules, $ruleIndex, 1);
			$wpdb->update ($eventsTable, [ 'rulesJson' => wp_json_encode ($rules)], [ 'id' => $eventId], [ '%s'], [ '%d']);
			echo '<div class="notice notice-success"><p>Regla eliminada correctamente.</p></div>';
			$handled = true;
		}
	}

	return $handled;
}


// zg_handle_detail_event_deleterule

/**
 * Vista principal de detalle: lista secciones, formularios y reglas.
 *
 * @param int $eventId
 */
function zg_render_event_detail ($eventId)
{
	global $wpdb;
	$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson, rulesJson FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
	$sections = json_decode ($row->sectionsJson, true) ?: [ ];
	$rules = json_decode ($row->rulesJson, true) ?: [ ];

	// Volver al listado de eventos
	echo '<a href="' . esc_url (admin_url ('admin.php?page=zentrygate_events')) . '" class="button">← Volver</a>';

	// Secciones
	zg_render_sections_form ($eventId);
	zg_list_sections ($eventId, $sections);

	// Reglas
	if (! empty ($sections))
	{
		echo '<a href="' . esc_url (admin_url ('admin.php?page=zentrygate_events&action=detail&subaction=addrule&eventId=' . $eventId)) . '" class="button button-secondary" style="margin-top:10px;">➕ Añadir Regla</a>';
		zg_list_rules ($eventId, $rules);
	}
}


/**
 * Lista las secciones del evento.
 *
 * @param int $eventId
 * @param array $sections
 */
function zg_list_sections ($eventId, $sections)
{
	if (empty ($sections))
	{
		echo '<p>No hay secciones.</p>';
		return;
	}
	?>
    <h4>Secciones</h4>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th>Etiqueta</th>
                <th>Aforo</th>
                <th>Precio</th>
                <th>Oculto</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php

	foreach ($sections as $sec)
	:
		?>
                <tr>
                    <td><?php

		echo esc_html ($sec ['label']);
		?></td>
                    <td><?php

		echo $sec ['capacity'] === 0 ? '∞' : esc_html ($sec ['capacity']);
		?></td>
                    <td><?php

		echo esc_html (number_format ($sec ['price'], 2));
		?></td>
                    <td><?php

		echo $sec ['isHidden'] ? 'Sí' : 'No';
		?></td>
                    <td>
                        <a href="<?php
		echo esc_url (admin_url ("admin.php?page=zentrygate_events&action=detail&subaction=editsection&eventId={$eventId}&sectionId=" . urlencode ($sec ['id'])));
		?>" class="button" title="Editar sección">🖉</a>
		<a href="<?php
		echo esc_url (admin_url ("admin.php?page=zentrygate_events&action=detail&subaction=deletesection&eventId={$eventId}&sectionId=" . urlencode ($sec ['id'])));
		?>" class="button" title="Eliminar sección">🗑</a>
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
 * Formulario para añadir una nueva sección.
 *
 * @param int $eventId
 */
function zg_render_sections_form ($eventId)
{
	?>
    <h4>Añadir Sección</h4>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="eventId" value="<?php

	echo esc_attr ($eventId);
	?>">
        <input type="text" name="sectionLabel" placeholder="Etiqueta de sección" required>
        <input type="number" name="sectionCapacity" placeholder="Aforo (0=infinito)" min="0" required>
        <input type="number" step="0.01" name="sectionPrice" placeholder="Precio (€)" required>
        <label><input type="checkbox" name="sectionHidden"> Oculto</label>
        <button type="submit" name="zg_add_section" class="button" title="Añadir sección">➕ Agregar</button>
    </form>
    <?php
}


/**
 * Formulario para editar una sección existente.
 *
 * @param int $eventId
 * @param string $sectionId
 */
function zg_render_edit_section_form ($eventId, $sectionId)
{
	global $wpdb;
	$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
	$sections = json_decode ($row->sectionsJson, true) ?: [ ];
	$current = null;
	foreach ($sections as $sec)
	{
		if ($sec ['id'] === $sectionId)
		{
			$current = $sec;
			break;
		}
	}
	if (! $current)
	{
		echo '<div class="notice notice-error"><p>Sección no encontrada.</p></div>';
		return;
	}
	?>
    <h4>Editar Sección</h4>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="eventId" value="<?php

	echo esc_attr ($eventId);
	?>">
        <input type="hidden" name="sectionId" value="<?php

	echo esc_attr ($sectionId);
	?>">
        <input type="text" name="sectionLabel" placeholder="Etiqueta de sección" value="<?php

	echo esc_attr ($current ['label']);
	?>" required>
        <input type="number" name="sectionCapacity" placeholder="Aforo (0=infinito)" min="0" value="<?php

	echo esc_attr ($current ['capacity']);
	?>" required>
        <input type="number" step="0.01" name="sectionPrice" placeholder="Precio (€)" value="<?php

	echo esc_attr ($current ['price']);
	?>" required>
        <label><input type="checkbox" name="sectionHidden" <?php

	checked ($current ['isHidden']);
	?>> Oculto</label>
        <button type="submit" name="zg_edit_section" class="button" title="Actualizar sección">✔️ Guardar</button>
    </form>
    <?php
}


/**
 * Lista las reglas del evento.
 *
 * @param int $eventId
 * @param array $rules
 */
function zg_list_rules ($eventId, $rules)
{
	if (empty ($rules))
	{
		echo '<p>No hay reglas.</p>';
		return;
	}
	?>
    <h4>Reglas</h4>
    <table class="widefat fixed striped">
        <thead>
            <tr><th>Nombre</th><th>Condiciones</th><th>Opcional</th><th>Acción</th><th>Acción</th></tr>
        </thead>
        <tbody>
            <?php

	foreach ($rules as $index => $r)
	:
		?>
                <tr>
                    <td><?php

		echo esc_html ($r ['rule']);
		?></td>
                    <td><?php

		echo esc_html (join (', ', $r ['cond']));
		?></td>
                    <td><?php

		echo ! empty ($r ['optional']) ? 'Sí' : 'No';
		?></td>
                    <td><?php

		echo esc_html ($r ['action']);
		?></td>
                    <td>
                        <a href="<?php

		echo esc_url (admin_url ("admin.php?page=zentrygate_events&action=detail&subaction=editrule&eventId={$eventId}&ruleIndex={$index}"));
		?>" class="button" title="Editar regla">🖉</a>
                        <a href="<?php

		echo esc_url (admin_url ("admin.php?page=zentrygate_events&action=detail&subaction=deleterule&eventId={$eventId}&ruleIndex={$index}"));
		?>" class="button" onclick="return confirm('¿Eliminar esta regla?');" title="Eliminar regla">🗑️</a>
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
 * Formulario para añadir una nueva regla.
 *
 * @param int $eventId
 */
function zg_render_add_rule_form ($eventId)
{
	global $wpdb;
	$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
	$sections = json_decode ($row->sectionsJson, true) ?: [ ];
	?>
    <h4>Añadir Regla</h4>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="eventId" value="<?php

	echo esc_attr ($eventId);
	?>">
        <label>Nombre de la regla:<br>
            <input type="text" name="ruleName" required>
        </label><br>
        <label>Condiciones:<br>
        <?php

	foreach ($sections as $sec)
	:
		?>
            <label><input type="checkbox" name="ruleConds[]" value="<?php

		echo esc_attr ($sec ['id']);
		?>"> <?php

		echo esc_html ($sec ['label']);
		?></label><br>
        <?php
	endforeach
	;
	?>
        </label>
        <label>Opcional: <input type="checkbox" name="ruleOptional"></label><br>
        <label>Acción:<br>
            <input type="text" name="ruleAction" placeholder="page:slug o stripe:id" required>
        </label><br>
        <button type="submit" name="zg_add_rule" class="button" title="Añadir regla">➕ Agregar</button>
    </form>
    <?php
}


/**
 * Formulario para editar una regla existente.
 *
 * @param int $eventId
 * @param int $ruleIndex
 */
function zg_render_edit_rule_form ($eventId, $ruleIndex)
{
	global $wpdb;
	$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson, rulesJson FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
	$sections = json_decode ($row->sectionsJson, true) ?: [ ];
	$rules = json_decode ($row->rulesJson, true) ?: [ ];

	if (! isset ($rules [$ruleIndex]))
	{
		echo '<div class="notice notice-error"><p>Regla no encontrada.</p></div>';
		return;
	}
	$current = $rules [$ruleIndex];
	?>
    <h4>Editar Regla</h4>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="eventId" value="<?php

	echo esc_attr ($eventId);
	?>">
        <input type="hidden" name="ruleIndex" value="<?php

	echo esc_attr ($ruleIndex);
	?>">
        <label>Nombre de la regla:<br>
            <input type="text" name="ruleName" value="<?php

	echo esc_attr ($current ['rule']);
	?>" required>
        </label><br>
        <label>Condiciones:<br>
        <?php

	foreach ($sections as $sec)
	:
		$checked = in_array ($sec ['id'], $current ['cond'], true);
		?>
            <label><input type="checkbox" name="ruleConds[]" value="<?php

		echo esc_attr ($sec ['id']);
		?>" <?php

		checked ($checked);
		?>> <?php

		echo esc_html ($sec ['label']);
		?></label><br>
        <?php
	endforeach
	;
	?>
        </label>
        <label>Opcional: <input type="checkbox" name="ruleOptional" <?php

	checked (! empty ($current ['optional']));
	?>></label><br>
        <label>Acción:<br>
            <input type="text" name="ruleAction" value="<?php

	echo esc_attr ($current ['action']);
	?>" required>
        </label><br>
        <button type="submit" name="zg_edit_rule" class="button" title="Actualizar regla">✔️ Guardar</button>
    </form>
    <?php
}
