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
	$event = $wpdb->get_row ($wpdb->prepare ("SELECT name, date, sectionsJson, rulesJson FROM {$table} WHERE id = %d", $eventId));
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
		// YAGNI: rename rules to triggers

		case 'addrule':

			$rules = [ ];

			if (! zg_handle_detail_event_addrule ($eventId, $rules))
			{

				$sections = json_decode ($event->sectionsJson, false) ?: [ ];
				zg_render_rule_form ($eventId, $sections, null);
				$executed = true;
			}
			else
			{
				$event->rulesJson = wp_json_encode ($rules, JSON_UNESCAPED_UNICODE);
			}
			break;

		case 'editrule':
			$rules = json_decode ($event->rulesJson, true) ?: [ ];

			// Si no procesó el POST de edición, renderizamos el formulario
			if (! zg_handle_detail_event_editrule ($eventId, $rules))
			{
				$sections = json_decode ($event->sectionsJson, false) ?: [ ];

				// Obtenemos el ruleId del GET y lo pasamos a entero
				$ruleId = intval ($_GET ['ruleId'] ?? - 1);
				foreach ($rules as $rule)
				{
					if ($rule ['id'] == $ruleId)
					{
						zg_render_rule_form ($eventId, $sections, $rule);
						$executed = true;
						break;
					}
				}
			}
			else
			{
				$event->rulesJson = wp_json_encode ($rules, JSON_UNESCAPED_UNICODE);
			}
			break;

		case 'deleterule':
			$rules = json_decode ($event->rulesJson, true) ?: [ ];
			if (zg_handle_detail_event_deleterule ($rules))
			{
				$event->rulesJson = wp_json_encode ($rules, JSON_UNESCAPED_UNICODE);
			}
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
		$sections = json_decode ($event->sectionsJson, true) ?: [ ];
		$rules = json_decode ($event->rulesJson, true) ?: [ ];
		zg_render_event_detail ($eventId, $sections, $rules);
	}
}


// ---------------------------------------------------------------------------------------
/*
 * Estructura JSON en la tabla wp_zgEvents, campo sectionsJson:
 * [
 * {
 * "id": "12345678", // identificador numérico único (oculto en interfaz)
 * "label": "Day 1 - Morning", // nombre visible de la sección
 * "capacity": 50, // aforo máximo (0 = indefinido)
 * "price": 20.00, // precio en euros
 * "isHidden": false // indicador de sección oculta
 * },
 * ...
 * ]
 *
 */

// ---------------------------------------------------------------------------------------

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
 * Construye la estructura de una regla a partir de $_POST.
 *
 * @param array $post
 *        	El array completo de $_POST
 * @return array{label:string, triggers:int[], actions: array<int, array{pageId?:int, sectionId?:int}>}
 */
function zg_build_rule_from_post (array $post): array
{
	// 1) Label / descripción
	$label = isset ($post ['rule_description']) ? sanitize_text_field ($post ['rule_description']) : '';

	// 2) Triggers: sólo los sectionId marcados como "subscribed"
	$triggers = [ ];
	if (! empty ($post ['conditions']) && is_array ($post ['conditions']))
	{
		foreach ($post ['conditions'] as $sectionId => $op)
		{
			if ('subscribed' === $op)
			{
				$triggers [] = intval ($sectionId);
			}
		}
	}

	// 3) Actions: sacamos pageId o sectionId de cada entrada
	$actions = [ ];
	if (! empty ($post ['actions']) && is_array ($post ['actions']))
	{
		foreach ($post ['actions'] as $act)
		{
			// Mostrar página
			if (isset ($act ['pageId']))
			{
				$actions [] = [ 'showPage' => intval ($act ['pageId'])];
			}
			// Permitir suscribirnos a una sección del evento
			if (isset ($act ['sectionId']))
			{
				$actions [] = [ 'allowSectionSubscription' => intval ($act ['sectionId'])];
			}
		}
	}

	return [ 'name' => $label, 'triggers' => $triggers, 'actions' => $actions];
}


/**
 * Procesa la adición de una nueva regla a un evento dado.
 *
 * @param int $eventId
 *        	ID del evento.
 * @param array $rules
 *        	Array decodificado de rulesJson (puede estar vacío o no tener clave 'rules').
 * @return bool True si se ha añadido la regla correctamente, false si no se ha procesado o ha fallado.
 */
function zg_handle_detail_event_addrule ($eventId, array &$rules): bool
{
	global $wpdb;
	$table = $wpdb->prefix . 'zgEvents';

	if (empty ($_POST ['zg_add_rule']) || intval ($_POST ['event_id']) !== $eventId)
	{
		return false;
	}

	// Verificar nonce
	if (! wp_verify_nonce ($_POST ['zg_add_rule_nonce'], 'zg_add_rule_' . $eventId))
	{
		wp_die ('No autorizado.');
	}

	// Asegurarnos de que exista el array de reglas
	if (! is_array ($rules))
	{
		$rules = [ ];
	}

	// Build the new rule from POST data
	$newRule = zg_build_rule_from_post ($_POST);
	$newRule ['id'] = (string) abs (crc32 (uniqid ())); // Generate a integer unique ID for the rule

	// Append y actualizar en BB.DD.
	$rules [] = $newRule;
	$updated = $wpdb->update ($table, [ 'rulesJson' => wp_json_encode ($rules)], [ 'id' => $eventId], [ '%s'], [ '%d']);
	if (false === $updated)
	{
		wp_die ('Error guardando la regla.');
	}

	// Redirigir para evitar reenvío
	echo '<div class="notice notice-success"><p>Regla añadida correctamente.</p></div>';
	return true;
}


/**
 * Edit an existing rule for the event.
 *
 * @return bool True if the rule was edited, false otherwise.
 */
function zg_handle_detail_event_editrule ($eventId, array &$rules)
{
	global $wpdb;
	$table = $wpdb->prefix . 'zgEvents';

	if (empty ($_POST ['zg_edit_rule']) || intval ($_POST ['event_id']) !== $eventId)
	{
		return false;
	}

	// Verificar nonce
	if (! wp_verify_nonce ($_POST ['zg_add_rule_nonce'], 'zg_add_rule_' . $eventId))
	{
		wp_die ('No autorizado.');
	}

	// Asegurarnos de que exista el array de reglas
	if (! is_array ($rules))
	{
		$rules = [ ];
	}

	// Append y actualizar en BB.DD.
	$ruleId = intval ($_POST ['rule_id']);
	foreach ($rules as $idx => $rule)
	{
		if ($rule ['id'] == $ruleId)
		{
			// Recreate the rule from POST data
			$rules [$idx] = zg_build_rule_from_post ($_POST);
			break;
		}
	}

	$updated = $wpdb->update ($table, [ 'rulesJson' => wp_json_encode ($rules)], [ 'id' => $eventId], [ '%s'], [ '%d']);
	if (false === $updated)
	{
		wp_die ('Error actualizando la regla.');
	}

	// Redirigir para evitar reenvío
	echo '<div class="notice notice-success"><p>Regla modificada correctamente.</p></div>';
	return true;

	// --------------------------
	/*
	 * global $wpdb;
	 * $eventsTable = $wpdb->prefix . 'zgEvents';
	 * $eventId = intval ($_REQUEST ['eventId'] ?? 0);
	 * $handled = false;
	 *
	 * if (isset ($_POST ['zg_edit_rule']))
	 * {
	 *
	 * // 1) Obtengo el ID de la regla que vino en el form
	 * $postedRule = $_POST ['rule'];
	 * $postedId = intval ($postedRule ['id']);
	 *
	 * // 2) Traigo el JSON actual de la BD
	 * $row = $wpdb->get_row ($wpdb->prepare ("SELECT rulesJson FROM {$eventsTable} WHERE id = %d", $eventId));
	 * $rules = json_decode ($row->rulesJson, true) ?: [ ];
	 *
	 * // 3) Recorro y actualizo solo la regla cuyo 'id' coincide
	 * foreach ($rules as &$r)
	 * {
	 * if (intval ($r ['id']) === $postedId)
	 * {
	 * // 3.a) Descripción
	 * $r ['description'] = sanitize_text_field ($postedRule ['description']);
	 * // 3.b) Condiciones
	 * $r ['conditions'] = [ ];
	 * if (! empty ($_POST ['conditions']) && is_array ($_POST ['conditions']))
	 * {
	 * foreach ($_POST ['conditions'] as $sectionId => $ops)
	 * {
	 * foreach ((array) $ops as $op)
	 * {
	 * if (in_array ($op, [ 'subscribed', 'notSubscribed'], true))
	 * {
	 * $r ['conditions'] [] = [ 'sectionId' => intval ($sectionId), 'operator' => sanitize_text_field ($op)];
	 * }
	 * }
	 * }
	 * }
	 * // 3.c) Acciones
	 * $r ['actions'] = [ ];
	 * if (! empty ($_POST ['actions']) && is_array ($_POST ['actions']))
	 * {
	 * foreach ($_POST ['actions'] as $act)
	 * {
	 * if (empty ($act ['type']))
	 * {
	 * continue;
	 * }
	 * $a = [ 'type' => sanitize_text_field ($act ['type'])];
	 * if (isset ($act ['pageId']))
	 * {
	 * $a ['pageId'] = intval ($act ['pageId']);
	 * }
	 * if (isset ($act ['sectionId']))
	 * {
	 * $a ['sectionId'] = intval ($act ['sectionId']);
	 * }
	 * $r ['actions'] [] = $a;
	 * }
	 * }
	 * break;
	 * }
	 * }
	 * unset ($r);
	 *
	 * // 4) Vuelvo a codificar y actualizar en BD
	 * $wpdb->update ($eventsTable, [ 'rulesJson' => wp_json_encode ($rules, JSON_UNESCAPED_UNICODE)], [ 'id' => $eventId], [ '%s'], [ '%d']);
	 *
	 * echo '<div class="notice notice-success"><p>Regla actualizada correctamente.</p></div>';
	 * $handled = true;
	 * }
	 *
	 * return $handled;
	 */
}


// zg_handle_detail_event_editrule

/**
 * Delete a rule from the event.
 *
 * @return bool True if the rule was deleted, false otherwise.
 */
function zg_handle_detail_event_deleterule (&$rules)
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);

	$handled = false;

	if (isset ($_REQUEST ['subaction']) && $_REQUEST ['subaction'] === 'deleterule' && isset ($_GET ['ruleId']))
	{
		$ruleId = intval ($_GET ['ruleId']);

		foreach ($rules as $index => $rule)
		{
			if ((string) $rule ['id'] === (string) $ruleId)
			{
				unset ($rules [$index]);

				$wpdb->update ($eventsTable, [ 'rulesJson' => wp_json_encode ($rules)], [ 'id' => $eventId], [ '%s'], [ '%d']);
				echo '<div class="notice notice-success"><p>Regla eliminada correctamente.</p></div>';
				$handled = true;

				break;
			}
		}
	}

	return $handled;
}


/**
 * Vista principal de detalle: lista secciones, formularios y reglas.
 *
 * @param int $eventId
 */
function zg_render_event_detail ($eventId, $sections, $rules)
{
	// Volver al listado de eventos
	echo '<a href="' . esc_url (admin_url ('admin.php?page=zentrygate_events')) . '" class="button">← Volver</a>';

	// Secciones
	zg_render_sections_form ($eventId);
	zg_list_sections ($eventId, $sections);

	// Reglas
	if (! empty ($sections))
	{
		echo '<a href="' . esc_url (admin_url ('admin.php?page=zentrygate_events&action=detail&subaction=addrule&eventId=' . $eventId)) . '" class="button button-secondary" style="margin-top:10px;">➕ Añadir Regla</a>';
		zg_list_rules ($eventId, $rules, $sections);
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
    <form method="post" style="margin-bottom:20px;">
        <fieldset>
            <legend>Editar Sección</legend>

            <input type="hidden" name="eventId" value="<?php

	echo esc_attr ($eventId);
	?>">
            <input type="hidden" name="sectionId" value="<?php

	echo esc_attr ($sectionId);
	?>">

            <p>
                <label for="sectionLabel_<?php

	echo esc_attr ($sectionId);
	?>">Etiqueta de sección</label><br>
                <input id="sectionLabel_<?php

	echo esc_attr ($sectionId);
	?>" type="text" name="sectionLabel" value="<?php

	echo esc_attr ($current ['label']);
	?>" required>
            </p>

            <p>
                <label for="sectionCapacity_<?php

	echo esc_attr ($sectionId);
	?>">Aforo (0 = infinito)</label><br>
                <input id="sectionCapacity_<?php

	echo esc_attr ($sectionId);
	?>" type="number" name="sectionCapacity" min="0" value="<?php

	echo esc_attr ($current ['capacity']);
	?>" required>
            </p>

            <p>
                <label for="sectionPrice_<?php

	echo esc_attr ($sectionId);
	?>">Precio (€)</label><br>
                <input id="sectionPrice_<?php

	echo esc_attr ($sectionId);
	?>" type="number" step="0.01" name="sectionPrice" value="<?php

	echo esc_attr ($current ['price']);
	?>" required>
            </p>

            <p>
                <label for="sectionHidden_<?php

	echo esc_attr ($sectionId);
	?>">
                    <input id="sectionHidden_<?php

	echo esc_attr ($sectionId);
	?>" type="checkbox" name="sectionHidden" <?php

	checked ($current ['isHidden']);
	?>>
                    Oculto
                </label>
            </p>

            <button type="submit" name="zg_edit_section" class="button" title="Actualizar sección">
                ✔️ Guardar
            </button>
        </fieldset>
    </form>
    <?php
}


/**
 * Renderiza la lista de reglas según el formato JSON:
 * [
 * {
 * "name": "Nombre de la regla",
 * "triggers": [301840602, 2280640671],
 * "actions": [
 * {"showPage":1},
 * {"allowSectionSubscription":895373093},
 * {"showPage":46}
 * ],
 * "id": "1388251998"
 * },
 * …
 * ]
 *
 * @param int $eventId
 *        	ID del evento en la BD
 * @param array $ruleList
 *        	Array decodificado de JSON de reglas
 * @param array $sections
 *        	Array de secciones con ['id'] y ['label'] o ['title']
 */
function zg_list_rules (int $eventId, array $ruleList, array $sections)
{
	// Si no hay nada que listar
	if (empty ($ruleList))
	{
		echo '<p>No hay reglas.</p>';
		return;
	}

	// Obtener lista de páginas para mostrar en las acciones
	$wpPages = get_pages ([ 'sort_column' => 'post_title']);
	$pagesMap = [ ];

	foreach ($wpPages as $page)
	{
		$pagesMap [$page->ID] = $page->post_title;
	}

	// 1) Mapear las secciones para lookup rápido de etiquetas
	$labelMap = [ ];
	foreach ($sections as $s)
	{
		$labelMap [$s ['id']] = $s ['label'] ?? ($s ['title'] ?? $s ['id']);
	}

	// 2) Cabecera de tabla
	?>
    <h4>Reglas</h4>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th>Descripción</th>
                <th>Condiciones</th>
                <th>Acciones</th>
                <th>Editar</th>
                <th>Eliminar</th>
            </tr>
        </thead>
        <tbody>
    <?php

	// 3) Iterar cada regla
	foreach ($ruleList as $rule)
	{
		$ruleId = esc_attr ($rule ['id']);
		?>
        <tr>
            <!-- Descripción / nombre -->
            <td><?=esc_html ($rule ['name'] ?? '');?></td>

            <!-- Triggers: array de sectionId -->
            <td>
                <?php
		if (empty ($rule ['triggers']) || ! is_array ($rule ['triggers']))
		{
			echo '&mdash;';
		}
		else
		{
			$parts = [ ];
			foreach ($rule ['triggers'] as $sectionId)
			{
				$label = $labelMap [intval ($sectionId)] ?? intval ($sectionId);
				$parts [] = esc_html ($label);
			}
			echo esc_html (implode (', ', $parts));
		}
		?>
            </td>

            <!-- Actions: cada elemento es un array con un único key=>value -->
            <td>
                <?php
		if (empty ($rule ['actions']) || ! is_array ($rule ['actions']))
		{
			echo '&mdash;';
		}
		else
		{
			$acts = [ ];
			foreach ($rule ['actions'] as $act)
			{
				if (! is_array ($act))
				{
					continue;
				}
				// Cada $act tiene la forma [ tipoAccion => idEntidad ]
				foreach ($act as $type => $value)
				{
					switch ($type)
					{
						case 'showPage':
							$acts [] = sprintf ('Mostrar página "%s".', esc_html ($pagesMap [$value]));
							break;

						case 'allowSectionSubscription':
							$label = $labelMap [intval ($value)] ?? intval ($value);
							$acts [] = sprintf ('Permitir suscripción sección "%s".', esc_html ($label));
							break;

						default:
							// Acción desconocida: muestro tipo y valor
							$acts [] = sprintf ('%s: %s', esc_html ($type), esc_html ($value));
							break;
					}
				}
			}
			echo implode ('<br > ', $acts);
		}
		?>
            </td>

            <!-- Botón Editar -->
            <td>
                <a
                    href="<?=esc_url (admin_url ("admin.php?page=zentrygate_events&amp;action=detail&amp;subaction=editrule&amp;eventId={$eventId}&amp;ruleId={$ruleId}"));?>"
                    class="button"
                    title="Editar regla"
                >🖉</a>
            </td>

            <!-- Botón Eliminar -->
            <td>
                <a
                    href="<?=esc_url (admin_url ("admin.php?page=zentrygate_events&amp;action=detail&amp;subaction=deleterule&amp;eventId={$eventId}&amp;ruleId={$ruleId}"));?>"
                    class="button"
                    onclick="return confirm('¿Eliminar esta regla?');"
                    title="Eliminar regla"
                >🗑️</a>
            </td>
        </tr>
        <?php
	}
	?>
        </tbody>
    </table>
    <?php
}


function zg_render_rule_form_actions (array $actions)
{
	$idx = 0;
	foreach ($actions as $action)
	{
		$idx ++;
		// Cada $action es un array con un único par tipo=>valor
		$type = key ($action);
		$value = current ($action);
		?>
        <div
            class="rule-action-row"
            data-index="<?=esc_attr ($idx);?>"
            <?php
		if ($type === 'showPage')
		{
			echo ' data-page-id="' . esc_attr ($value) . '"';
		}
		elseif ($type === 'allowSectionSubscription')
		{
			echo ' data-section-id="' . esc_attr ($value) . '"';
		}
		?>
            style="padding:10px;margin-bottom:8px;position:relative;border-bottom:1px solid #ddd;"
        >
            <?php

		if ($idx > 1)
		:
			?>
                <button type="button" class="remove-action" style="position:absolute;top:5px;right:5px;">
                    ×
                </button>
            <?php endif;

		?>

            <p>
                <label>
                    Tipo:
                    <select name="actions[<?=esc_attr ($idx);?>][type]" class="action-type">
                        <option value="showPage" <?=selected ($type, 'showPage', false);?>>
                            Mostrar página
                        </option>
                        <option value="allowSectionSubscription" <?=selected ($type, 'allowSectionSubscription', false);?>>
                            Permitir suscripción
                        </option>
                    </select>
                </label>
            </p>
            <div class="action-params"></div>
        </div>
        <?php
	}
}


function zg_render_rule_form_triggers ($sections, $condMap)
{
	foreach ($sections as $sec)
	{

		if ($sec->isHidden) continue;
		?>
		
    <tr>
        <td style="padding:8px;border-bottom:1px solid #ddd;vertical-align:middle;">
            <strong><?=esc_html ($sec->label);?></strong>
        </td>
        <td style="padding:8px;border-bottom:1px solid #ddd;">
            <label style="margin-right:10px;">
                <input
                    type="radio"
                    name="conditions[<?=esc_attr ($sec->id);?>]"
                    value="subscribed"
                    <?=checked (in_array ($sec->id, $condMap), true, false);?>
                > Suscrito
            </label>
            <label>
                <input
                    type="radio"
                    name="conditions[<?=esc_attr ($sec->id);?>]"
                    value="notSubscribed"
                    <?=checked (! in_array ($sec->id, $condMap), true, false);?>
                > No suscrito
            </label>
        </td>
    </tr>
		<?php
	}
}


/**
 * Render the "Add/Edit Rule" form for a given event, preserving original POST field names.
 *
 * @param int $eventId
 *        	ID del evento
 * @param stdClass[] $sections
 *        	Array de secciones (->id, ->label, ->isHidden)
 * @param array|null $ruleData
 *        	Datos de la regla precargada o null para nueva
 */
function zg_render_rule_form (int $eventId, array $sections, ?array $ruleData = null)
{
	$isEdit = null !== $ruleData;

	// Valores por defecto para nueva regla
	if (! $isEdit)
	{
		$ruleData = [ 'id' => '', 'description' => '', 'conditions' => [ ], 'actions' => [ ]];
	}

	// Mapeo de condiciones existentes (sectionId => operator)
	$condMap = is_array ($ruleData ['triggers']) ? $ruleData ['triggers'] : [ ];

	// Asegurar al menos una acción
	$actions = $ruleData ['actions'] ?: [ [ 'showPage' => '']];

	// Datos para JS: páginas y secciones ocultas
	$wpPages = get_pages ([ 'sort_column' => 'post_title']);
	$pagesJs = array_map (fn ($p) => [ 'ID' => $p->ID, 'post_title' => $p->post_title], $wpPages);
	$hiddenJs = [ ];
	foreach ($sections as $sec)
	{
		if ($sec->isHidden)
		{
			$hiddenJs [] = [ 'id' => $sec->id, 'label' => $sec->label];
		}
	}

	$actionName = $isEdit ? 'zg_edit_rule' : 'zg_add_rule';
	?>
    <form method="post" class="zg-rule-form">
        <h2><?=$isEdit ? 'Editar regla' : 'Crear nueva regla';?></h2>
        <input type="hidden" name="<?=esc_attr ($actionName);?>" value="1">
        <?php

	wp_nonce_field ("{$actionName}_{$eventId}", "{$actionName}_nonce");
	?>
        <input type="hidden" name="event_id" value="<?=esc_attr ($eventId);?>">
        <?php

	if ($isEdit)
	{
		echo '<input type="hidden" name="rule_id" value="' . esc_attr ($ruleData ['id']) . '">'; // Indica que es una edición
	}

	?>

        <table class="form-table">
            <tr>
                <th><label for="rule_description">Descripción</label></th>
                <td>
                    <input
                        type="text"
                        id="rule_description"
                        name="rule_description"
                        class="regular-text"
                        value="<?=esc_attr ($ruleData ['name']);?>"
                        required
                    >
                </td>
            </tr>
            <tr valign="top">
                <th>Condiciones</th>
                <td>
                    <table style="width:100%;border-collapse:collapse;">
                        <?php
	zg_render_rule_form_triggers ($sections, $condMap);

	?>
                    </table>
                </td>
            </tr>
            <tr valign="top">
                <th>Acciones</th>
                <td>
                    <div id="zg-actions-container">
                        <?php
	zg_render_rule_form_actions ($actions);

	?>
                    </div>
                    <p><button type="button" id="add-action">+ Añadir acción</button></p>
                </td>
            </tr>
        </table>

        <?php

	submit_button ($isEdit ? 'Actualizar regla' : 'Guardar regla');
	?>
        <a href="<?=esc_url (admin_url ('admin.php?page=zentrygate_events&action=detail&eventId=' . $eventId));?>" class="button-secondary" style="margin-left:10px;">← Volver</a>
    </form>

    <script>
    (function() {
        const pages = <?=wp_json_encode ($pagesJs);?>;
        const hiddenSections = <?=wp_json_encode ($hiddenJs);?>;
        const container = document.getElementById('zg-actions-container');
        const addBtn = document.getElementById('add-action');
        let actionIndex = container.querySelectorAll('.rule-action-row').length;

        function makeOption(value, text, selected) {
            const opt = document.createElement('option');
            opt.value = value;
            opt.text = text;
            if (selected) opt.selected = true;
            return opt;
        }

        function renderParams(row) {
            const idx = row.dataset.index;
            const type = row.querySelector('.action-type').value;
            row.dataset.index = idx;
            const paramsDiv = row.querySelector('.action-params');
            paramsDiv.innerHTML = '';

            if (type === 'showPage') {
                const p = document.createElement('p');
                const lbl = document.createElement('label'); lbl.textContent = 'Página a mostrar: ';
                const sel = document.createElement('select'); sel.name = `actions[${idx}][pageId]`;
                pages.forEach(pg => sel.appendChild(makeOption(pg.ID, pg.post_title, row.dataset.pageId == pg.ID)));
                lbl.appendChild(sel); p.appendChild(lbl); paramsDiv.appendChild(p);

            } else if (type === 'unhideSection') {
                const p = document.createElement('p');
                const lbl = document.createElement('label'); lbl.textContent = 'Sección oculta: ';
                const sel = document.createElement('select'); sel.name = `actions[${idx}][sectionId]`;
                hiddenSections.forEach(sec => sel.appendChild(makeOption(sec.id, sec.label, row.dataset.sectionId == sec.id)));
                lbl.appendChild(sel); p.appendChild(lbl); paramsDiv.appendChild(p);
            }
        }

        // Render inicial
        container.querySelectorAll('.rule-action-row').forEach(row => {
            renderParams(row);
            row.querySelector('.action-type').addEventListener('change', () => renderParams(row));
            const btn = row.querySelector('.remove-action'); if (btn) btn.addEventListener('click', () => row.remove());
        });

        // Añadir nueva acción
        addBtn.addEventListener('click', () => {
            const template = container.querySelector('.rule-action-row');
            const clone = template.cloneNode(true);
            clone.dataset.index = actionIndex;
            clone.removeAttribute('data-page-id'); clone.removeAttribute('data-section-id');
            clone.querySelectorAll('input, select').forEach(el => el.value = '');
            let btn = clone.querySelector('.remove-action');
            if (!btn) {
                btn = document.createElement('button'); btn.type = 'button'; btn.className = 'remove-action'; btn.textContent = '×'; btn.style.cssText = 'position:absolute;top:5px;right:5px;'; clone.appendChild(btn);
            }
            btn.addEventListener('click', () => clone.remove());
            clone.querySelector('.action-type').addEventListener('change', () => renderParams(clone));
            container.appendChild(clone); renderParams(clone);
            actionIndex++;
        });
    })();
    </script>
<?php
}
