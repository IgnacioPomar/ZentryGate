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
function zg_render_events_page()
{
    echo '<div class="wrap">';
    $action = $_GET['action'] ?? '';
    $eventId = intval($_GET['eventId'] ?? 0);
    $executed = false;

    switch ($action) {
        case 'editevent':
            if (! zg_handle_edit_event_action()) {
                $executed = true;
                echo '<h2>Editar Evento</h2>';
                zg_render_edit_event_page($eventId);
            }
            break;

        case 'detail':
            if (! zg_handle_detail_event_actions()) {
                $executed = true;
                echo '<h2>ZentryGate - Detalle de Evento</h2>';
                zg_render_event_detail($eventId);
            }
            break;

        default:
            if (zg_handle_general_event_actions()) {
                $executed = true;
            }
    }

    if (! $executed) {
        echo '<h2>ZentryGate - Gestión de Eventos</h2>';
        zg_render_create_event_form();
        zg_list_created_events();
    }

    echo '</div>';
}

/**
 * Crea o elimina eventos.
 *
 * @return bool True si procesó una acción, false en caso contrario.
 */
function zg_handle_general_event_actions()
{
    global $wpdb;
    $eventsTable = "{$wpdb->prefix}zgEvents";
    $handled = false;

    if (isset($_POST['zg_create_event'])) {
        $wpdb->insert($eventsTable, [
            'name' => sanitize_text_field($_POST['eventName']),
            'date' => sanitize_text_field($_POST['eventDate']),
            'sectionsJson' => '[]',
            'rulesJson' => '[]'
        ]);
        echo '<div class="notice notice-success"><p>Evento creado correctamente.</p></div>';
        $handled = true;
    }

    if (isset($_POST['zg_delete_event'])) {
        $wpdb->delete($eventsTable, [
            'id' => intval($_POST['eventId'])
        ]);
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
function zg_handle_edit_event_action()
{
    global $wpdb;
    $eventsTable = "{$wpdb->prefix}zgEvents";
    if (isset($_POST['zg_edit_event'])) {
        $eventId = intval($_POST['eventId']);
        $wpdb->update($eventsTable, [
            'name' => sanitize_text_field($_POST['eventName']),
            'date' => sanitize_text_field($_POST['eventDate'])
        ], [
            'id' => $eventId
        ]);
        echo '<div class="notice notice-success"><p>Evento actualizado correctamente.</p></div>';
        return true;
    }
    return false;
}

/**
 * Gestiona secciones y reglas dentro de un evento.
 *
 * @return bool True si procesó alguna acción, false en caso contrario.
 */
function zg_handle_detail_event_actions()
{
    global $wpdb;
    $eventsTable = "{$wpdb->prefix}zgEvents";
    $capTable = "{$wpdb->prefix}zgCapacity";
    $eventId = intval($_GET['eventId'] ?? ($_POST['eventId'] ?? 0));
    $handled = false;

    // Añadir sección
    if (isset($_POST['zg_add_section'])) {
        $label = sanitize_text_field($_POST['sectionLabel']);
        $capacity = intval($_POST['sectionCapacity']);
        $price = floatval($_POST['sectionPrice']);
        $isHidden = isset($_POST['sectionHidden']);
        $data = $wpdb->get_var($wpdb->prepare("SELECT sectionsJson FROM $eventsTable WHERE id = %d", $eventId));
        $sections = json_decode($data, true) ?: [];
        $sectionId = 'sec_' . time();
        $sections[] = [
            'id' => $sectionId,
            'label' => $label,
            'capacity' => $capacity,
            'price' => $price,
            'isHidden' => $isHidden
        ];
        $wpdb->update($eventsTable, [
            'sectionsJson' => wp_json_encode($sections)
        ], [
            'id' => $eventId
        ]);
        $wpdb->insert($capTable, [
            'eventId' => $eventId,
            'sectionId' => $sectionId,
            'maxCapacity' => $capacity
        ]);
        echo '<div class="notice notice-success"><p>Sección creada correctamente.</p></div>';
        $handled = true;
    }

    // Editar sección
    if (isset($_POST['zg_edit_section'])) {
        $sectionId = sanitize_text_field($_POST['sectionId']);
        $label = sanitize_text_field($_POST['sectionLabel']);
        $capacity = intval($_POST['sectionCapacity']);
        $price = floatval($_POST['sectionPrice']);
        $isHidden = isset($_POST['sectionHidden']);
        $json = $wpdb->get_var($wpdb->prepare("SELECT sectionsJson FROM $eventsTable WHERE id = %d", $eventId));
        $sections = json_decode($json, true) ?: [];
        foreach ($sections as &$sec) {
            if ($sec['id'] === $sectionId) {
                $sec['label'] = $label;
                $sec['capacity'] = $capacity;
                $sec['price'] = $price;
                $sec['isHidden'] = $isHidden;
                break;
            }
        }
        $wpdb->update($eventsTable, [
            'sectionsJson' => wp_json_encode($sections)
        ], [
            'id' => $eventId
        ]);
        $wpdb->update($capTable, [
            'maxCapacity' => $capacity
        ], [
            'eventId' => $eventId,
            'sectionId' => $sectionId
        ]);
        echo '<div class="notice notice-success"><p>Sección actualizada correctamente.</p></div>';
        return true;
    }

    // Añadir regla
    if (isset($_POST['zg_add_rule'])) {
        $json = $wpdb->get_row($wpdb->prepare("SELECT sectionsJson, rulesJson FROM $eventsTable WHERE id = %d", $eventId));
        $sections = json_decode($json->sectionsJson, true) ?: [];
        if (! empty($sections)) {
            $rules = json_decode($json->rulesJson, true) ?: [];
            $rules[] = [
                'rule' => sanitize_text_field($_POST['ruleName']),
                'conds' => array_map('sanitize_text_field', (array) $_POST['ruleConds']),
                'action' => sanitize_text_field($_POST['ruleAction'])
            ];
            $wpdb->update($eventsTable, [
                'rulesJson' => wp_json_encode($rules)
            ], [
                'id' => $eventId
            ]);
            echo '<div class="notice notice-success"><p>Regla creada correctamente.</p></div>';
            return true;
        }
    }

    return $handled;
}

// ------------------------------------------------
// Vistas (formularios y listados)
// ------------------------------------------------

/**
 * Formulario para crear un nuevo evento.
 */
function zg_render_create_event_form()
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
function zg_list_created_events()
{
    global $wpdb;
    $events = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}zgEvents ORDER BY date DESC");
    ?>
    <h3>Eventos Creados</h3>
    <table class="widefat fixed striped">
        <thead><tr><th>Nombre</th><th>Fecha</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php

foreach ($events as $e) :
        ?>
            <tr>
                <td><?php

echo esc_html($e->name);
        ?></td>
                <td><?php

echo esc_html($e->date);
        ?></td>
                <td>
                    <a href="<?php

echo admin_url('admin.php?page=zentrygate_events&action=detail&eventId=' . $e->id);
        ?>" class="button" title="Gestionar">🔧</a>
                    <a href="<?php

echo admin_url('admin.php?page=zentrygate_events&action=editevent&eventId=' . $e->id);
        ?>" class="button" title="Editar">🖉</a>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="eventId" value="<?php

echo esc_attr($e->id);
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
function zg_render_edit_event_page($eventId)
{
    global $wpdb;
    $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
    if (! $event)
        return;
    ?>
    <h3>Editar Evento</h3>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="eventId" value="<?php

echo esc_attr($eventId);
    ?>">
        <input type="text" name="eventName" value="<?php

echo esc_attr($event->name);
    ?>" required>
        <input type="date" name="eventDate" value="<?php

echo esc_attr($event->date);
    ?>" required>
        <button type="submit" name="zg_edit_event" class="button button-primary">💾 Guardar</button>
        <a href="<?php

echo admin_url('admin.php?page=zentrygate_events');
    ?>" class="button">✖️ Cancelar</a>
    </form>
    <?php
}

/**
 * Detalle de un evento: secciones y reglas.
 */
function zg_render_event_detail($eventId)
{
    global $wpdb;
    $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}zgEvents WHERE id = %d", $eventId));
    $sections = json_decode($event->sectionsJson, true) ?: [];
    $rules = json_decode($event->rulesJson, true) ?: [];

    echo '<a href="' . admin_url('admin.php?page=zentrygate_events') . '" class="button">← Volver</a>';
    zg_render_sections_form($eventId);
    zg_list_sections($eventId, $sections);

    if (! empty($sections)) {
        echo '<a href="' . admin_url('admin.php?page=zentrygate_events&action=addrule&eventId=' . $eventId) . '" class="button button-secondary" style="margin-top:10px;">➕ Añadir Regla</a>';
        zg_list_rules($rules);
    }
}

/**
 * Formulario para añadir una nueva sección al evento.
 */
function zg_render_sections_form($eventId)
{
    ?>
    <h4>Añadir Sección</h4>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="eventId" value="<?php

echo esc_attr($eventId);
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
 * Lista las secciones del evento, sin mostrar su ID en la interfaz.
 */
function zg_list_sections($eventId, $sections)
{
    if (empty($sections)) {
        echo '<p>No hay secciones.</p>';
        return;
    }
    global $wpdb;
    $capTable = "{$wpdb->prefix}zgCapacity";
    ?>
    <h4>Secciones</h4>
    <table class="widefat fixed striped">
        <thead><tr><th>Etiqueta</th><th>Aforo</th><th>Precio</th><th>Oculto</th><th>Acción</th></tr></thead>
        <tbody>
        <?php

foreach ($sections as $sec) :
        ?>
            <tr>
                <td><?php

echo esc_html($sec['label']);
        ?></td>
                <td><?php

echo $sec['capacity'] === 0 ? '∞' : esc_html($sec['capacity']);
        ?></td>
                <td><?php

echo esc_html(number_format($sec['price'], 2));
        ?></td>
                <td><?php

echo $sec['isHidden'] ? 'Sí' : 'No';
        ?></td>
                <td>
                    <a href="<?php

echo admin_url('admin.php?page=zentrygate_events&action=editsection&eventId=' . $eventId . '&sectionId=' . urlencode($sec['id']));
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
 * Lista las reglas del evento.
 */
function zg_list_rules($rules)
{
    if (empty($rules)) {
        echo '<p>No hay reglas.</p>';
        return;
    }
    ?>
    <h4>Reglas</h4>
    <table class="widefat fixed striped">
        <thead><tr><th>Nombre</th><th>Condiciones</th><th>Acción</th></tr></thead>
        <tbody>
        <?php

foreach ($rules as $r) :
        ?>
            <tr>
                <td><?php

echo esc_html($r['rule']);
        ?></td>
                <td><?php

echo esc_html(join(', ', $r['conds']));
        ?></td>
                <td><?php

echo esc_html($r['action']);
        ?></td>
            </tr>
        <?php

endforeach
    ;
    ?>
        </tbody>
    </table>
    <?php
}
