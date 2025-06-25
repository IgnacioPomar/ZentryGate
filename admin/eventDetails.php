<?php
/**
 * eventDetails.php
 *
 * Gestión de secciones y reglas de un evento en el plugin ZentryGate.
 */
if (! defined ('ABSPATH'))
{
	exit ();
}


/**
 * Normaliza el array de secciones para que siempre use la clave 'id'.
 *
 * @param array $sections
 *        	Array decodificado de sectionsJson.
 * @return array Secciones con la clave 'id' unificada.
 */
function zg_normalize_sections (array $sections): array
{
	return array_map (function ($sec)
	{
		if (isset ($sec ['sectionId']) && ! isset ($sec ['id']))
		{
			$sec ['id'] = $sec ['sectionId'];
			unset ($sec ['sectionId']);
		}
		return $sec;
	}, $sections);
}


/**
 * Gestiona secciones y reglas dentro de un evento.
 *
 * @return bool True si procesó alguna acción, false en caso contrario.
 */
function zg_handle_detail_event_actions ()
{
	global $wpdb;
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$capTable = $wpdb->prefix . 'zgCapacity';
	$eventId = intval ($_REQUEST ['eventId'] ?? 0);
	$handled = false;

	// Añadir sección
	if (isset ($_POST ['zg_add_section']))
	{
		$label = sanitize_text_field ($_POST ['sectionLabel']);
		$capacity = intval ($_POST ['sectionCapacity']);
		$price = floatval ($_POST ['sectionPrice']);
		$isHidden = isset ($_POST ['sectionHidden']);
		$raw = json_decode ($wpdb->get_var ($wpdb->prepare ("SELECT sectionsJson FROM {$eventsTable} WHERE id = %d", $eventId)), true) ?: [ ];
		$sections = zg_normalize_sections ($raw);
		$sectionId = (string) abs (crc32 (uniqid ()));
		$sections [] = [ 'id' => $sectionId, 'label' => $label, 'capacity' => $capacity, 'price' => $price, 'isHidden' => $isHidden];
		$wpdb->update ($eventsTable, [ 'sectionsJson' => wp_json_encode ($sections)], [ 'id' => $eventId], [ '%s'], [ '%d']);
		$wpdb->insert ($capTable, [ 'eventId' => $eventId, 'sectionId' => $sectionId, 'maxCapacity' => $capacity], [ '%d', '%s', '%d']);
		echo '<div class="notice notice-success"><p>Sección creada correctamente.</p></div>';
		$handled = true;
	}

	// Editar sección
	if (isset ($_POST ['zg_edit_section']))
	{
		$sectionId = sanitize_text_field ($_POST ['sectionId']);
		$label = sanitize_text_field ($_POST ['sectionLabel']);
		$capacity = intval ($_POST ['sectionCapacity']);
		$price = floatval ($_POST ['sectionPrice']);
		$isHidden = isset ($_POST ['sectionHidden']);
		$raw = json_decode ($wpdb->get_var ($wpdb->prepare ("SELECT sectionsJson FROM {$eventsTable} WHERE id = %d", $eventId)), true) ?: [ ];
		$sections = zg_normalize_sections ($raw);
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

	// Añadir regla
	if (isset ($_POST ['zg_add_rule']))
	{
		$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson, rulesJson FROM {$eventsTable} WHERE id = %d", $eventId));
		$sectionsRaw = json_decode ($row->sectionsJson, true) ?: [ ];
		$sections = zg_normalize_sections ($sectionsRaw);
		if (! empty ($sections))
		{
			$rules = json_decode ($row->rulesJson, true) ?: [ ];
			$rules [] = [ 'rule' => sanitize_text_field ($_POST ['ruleName']), 'cond' => array_map ('sanitize_text_field', (array) $_POST ['ruleConds']), 'optional' => isset ($_POST ['ruleOptional']), 'action' => sanitize_text_field ($_POST ['ruleAction'])];
			$wpdb->update ($eventsTable, [ 'rulesJson' => wp_json_encode ($rules)], [ 'id' => $eventId], [ '%s'], [ '%d']);
			echo '<div class="notice notice-success"><p>Regla creada correctamente.</p></div>';
			$handled = true;
		}
	}

	// Editar regla
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

	// Eliminar regla
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

	switch ($subaction)
	{
		case 'addsection':
			if (zg_handle_detail_event_actions ())
			{
				zg_render_event_detail ($eventId);
			}
			break;

		case 'editsection':
			if (zg_handle_detail_event_actions ())
			{
				zg_render_event_detail ($eventId);
			}
			else
			{
				$sectionId = sanitize_text_field ($_GET ['sectionId'] ?? '');
				zg_render_edit_section_form ($eventId, $sectionId);
			}
			break;

		case 'addrule':
			if (zg_handle_detail_event_actions ())
			{
				zg_render_event_detail ($eventId);
			}
			else
			{
				zg_render_add_rule_form ($eventId);
			}
			break;

		case 'editrule':
			if (zg_handle_detail_event_actions ())
			{
				zg_render_event_detail ($eventId);
			}
			else
			{
				$ruleIndex = intval ($_GET ['ruleIndex'] ?? - 1);
				zg_render_edit_rule_form ($eventId, $ruleIndex);
			}
			break;

		case 'deleterule':
			if (zg_handle_detail_event_actions ())
			{
				zg_render_event_detail ($eventId);
			}
			break;

		default:
			if (! zg_handle_detail_event_actions ())
			{
				zg_render_event_detail ($eventId);
			}
			break;
	}
}


/**
 * Vista principal de detalle: lista secciones, formularios y reglas.
 *
 * @param int $eventId
 */
function zg_render_event_detail ($eventId)
{
	global $wpdb;
	$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson, rulesJson FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
	$rawSections = json_decode ($row->sectionsJson, true) ?: [ ];
	$sections = zg_normalize_sections ($rawSections);
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
	$raw = json_decode ($row->sectionsJson, true) ?: [ ];
	$sections = zg_normalize_sections ($raw);
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
	$sections = zg_normalize_sections (json_decode ($row->sectionsJson, true) ?: [ ]);
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
	$sections = zg_normalize_sections (json_decode ($row->sectionsJson, true) ?: [ ]);
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
