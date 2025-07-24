<?php


function zg_get_all_events ()
{
	global $wpdb;
	$table = $wpdb->prefix . 'zgEvents';
	return $wpdb->get_results ("SELECT * FROM $table ORDER BY date DESC");
}


function zg_render_dashboard_page ()
{
	global $wpdb, $zentrygatePluginVersion;
	$usersTable = $wpdb->prefix . 'zgUsers';
	$eventsTable = $wpdb->prefix . 'zgEvents';
	$reservationsTable = $wpdb->prefix . 'zgReservations';

	// Procesar eliminación si se ha enviado el formulario
	if (isset ($_POST ['zg_purge_old_users']))
	{
		// Obtener IDs de eventos pasados
		$pastEventIds = $wpdb->get_col ("SELECT id FROM $eventsTable WHERE date < CURDATE()");

		if (! empty ($pastEventIds))
		{
			$placeholders = implode (',', array_fill (0, count ($pastEventIds), '%d'));

			// Eliminar reservas de esos eventos
			$wpdb->query ($wpdb->prepare ("DELETE FROM $reservationsTable WHERE eventId IN ($placeholders)", ...$pastEventIds));

			// Obtener emails de usuarios no administradores que no tengan reservas en eventos futuros
			$emailsToDelete = $wpdb->get_col ("
                SELECT u.email
                FROM $usersTable u
                LEFT JOIN $reservationsTable r ON u.email = r.userEmail
                LEFT JOIN $eventsTable e ON r.eventId = e.id
                WHERE u.isAdmin = 0
                GROUP BY u.email
                HAVING MAX(e.date) IS NULL OR MAX(e.date) < CURDATE()
            ");

			if (! empty ($emailsToDelete))
			{
				$placeholders = implode (',', array_fill (0, count ($emailsToDelete), '%s'));
				$wpdb->query ($wpdb->prepare ("DELETE FROM $usersTable WHERE email IN ($placeholders)", ...$emailsToDelete));
				echo "<div class='notice notice-success'><p>Usuarios antiguos eliminados.</p></div>";
			}
			else
			{
				echo "<div class='notice notice-info'><p>No hay usuarios antiguos que eliminar.</p></div>";
			}
		}
		else
		{
			echo "<div class='notice notice-info'><p>No hay eventos antiguos registrados.</p></div>";
		}
	}
	?>

    <div class="wrap">
        <h1>ZentryGate</h1>
        <p><strong>Versión:</strong> <?=esc_html ($zentrygatePluginVersion)?></p>
        <p>Este plugin permite gestionar reservas para eventos con control de aforo, secciones, reglas condicionales y validación de usuarios registrados.</p>

        <form method="post" style="margin-top: 20px;">
            <input type="submit" name="zg_purge_old_users" class="button-secondary" value="Eliminar usuarios de eventos antiguos">
        </form>
    </div>
<?php
}


// --------------------------------------------------------------
// Selección de páginas para textos de formularios
// ---------------------------------------------------------------

// Registra los ajustes al inicializar el admin
function zg_register_form_texts_settings ()
{
	register_setting ('zg_form_texts_group', 'zg_cookie_prompt_page');
	register_setting ('zg_form_texts_group', 'zg_recovery_form_page');
	register_setting ('zg_form_texts_group', 'zg_login_form_page');

	add_settings_section ('zg_form_texts_section', 'Seleccione la página con el texto de cada formulario', '__return_false', 'zentrygate_form_texts');

	$fields = [ 'zg_cookie_prompt_page' => 'Cookie Prompt', 'zg_recovery_form_page' => 'Recovery Form', 'zg_login_form_page' => 'Login Form'];

	foreach ($fields as $option => $label)
	{
		add_settings_field ($option, $label, function () use ( $option)
		{
			$current = get_option ($option);
			wp_dropdown_pages ([ 'name' => $option, 'show_option_none' => '— Selecciona una página —', 'selected' => $current, 'option_none_value' => '0']);
		}, 'zentrygate_form_texts', 'zg_form_texts_section');
	}
}
add_action ('admin_init', 'zg_register_form_texts_settings');


// Renderiza la página de ajustes
function zg_render_form_texts_page ()
{
	?>
    <div class="wrap">
        <h1>Textos de Formularios</h1>
        <form method="post" action="options.php">
            <?php
	settings_fields ('zg_form_texts_group');
	do_settings_sections ('zentrygate_form_texts');
	submit_button ('Guardar cambios');
	?>
        </form>
    </div>
    <?php
}


