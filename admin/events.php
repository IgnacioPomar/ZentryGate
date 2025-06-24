<?php

function zg_render_events_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'zgEvents';

    if (isset($_POST['zg_create_event'])) {
        $name = sanitize_text_field($_POST['event_name']);
        $date = sanitize_text_field($_POST['event_date']);
        $sections = json_decode(stripslashes($_POST['sections_json']), true);
        $rules = json_decode(stripslashes($_POST['rules_json']), true);

        if ($name && $date) {
            $wpdb->insert($table, [
                'name' => $name,
                'date' => $date,
                'sectionsJson' => wp_json_encode($sections),
                'rulesJson' => wp_json_encode($rules)
            ]);
            echo "<div class='notice notice-success'><p>Evento creado</p></div>";
        } else {
            echo "<div class='notice notice-error'><p>Campos requeridos</p></div>";
        }
    }

    ?>
    <div class="wrap">
        <h2>Crear Evento</h2>
        <form method="post">
            <input type="text" name="event_name" placeholder="Nombre del evento" required><br><br>
            <input type="date" name="event_date" required><br><br>
            <textarea name="sections_json" rows="5" cols="60" placeholder='[{"id":"day1_morning","label":"Day 1 – Morning"}]' required></textarea><br><br>
            <textarea name="rules_json" rows="5" cols="60" placeholder='[{"rule":"Free Lunch","cond":["day1_morning"],"optional":false,"cost":0,"iftrue":"page:thanks"}]' required></textarea><br><br>
            <input type="submit" name="zg_create_event" class="button-primary" value="Crear evento">
        </form>
    </div>
<?php
}
