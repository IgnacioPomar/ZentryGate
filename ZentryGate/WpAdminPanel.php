<?php

namespace ZentryGate;

class WpAdminPanel
{


	public static function registerMenus ()
	{
		add_menu_page ('ZentryGate Admin', 'ZentryGate', 'manage_options', 'zentrygate', [ self::class, 'renderDashboard'], 'dashicons-groups');
		add_submenu_page ('zentrygate', 'Usuarios Admin', 'Usuarios', 'manage_options', 'zentrygate_users', [ self::class, 'render_users']);
		add_submenu_page ('zentrygate', 'Gestión de Eventos', 'Eventos', 'manage_options', 'zentrygate_events', [ self::class, 'render_events']);
		add_submenu_page ('zentrygate', 'Textos Formularios', 'Textos Formularios', 'manage_options', 'zentrygate_form_texts', [ self::class, 'renderFormTexts']);
	}


	// --------------------------------------------------------------
	// Dashboard Page
	// --------------------------------------------------------------
	public static function getAllEvents (): array
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgEvents';
		// Devuelve array de objetos stdClass como get_results()
		return (array) $wpdb->get_results ("SELECT * FROM {$table} ORDER BY date DESC");
	}


	public static function renderDashboard (): void
	{
		if (! current_user_can ('manage_options'))
		{
			wp_die (esc_html__ ('No tienes permisos suficientes.', 'zentrygate'));
		}

		// --- Procesar "Recreate Database"
		if (isset ($_POST ['zg_recreate_db']))
		{
			check_admin_referer ('zg_recreate_db_action', 'zg_recreate_db_nonce');
			try
			{
				Install::recreateDatabase ();
				echo "<div class='notice notice-success'><p>" . esc_html__ ('Base de datos recreada correctamente.', 'zentrygate') . "</p></div>";
			}
			catch (\Throwable $e)
			{
				echo "<div class='notice notice-error'><p>" . esc_html__ ('Error al recrear la base de datos: ', 'zentrygate') . esc_html ($e->getMessage ()) . "</p></div>";
			}
		}
		?>
        <div class="wrap">
            <h1>ZentryGate</h1>
            <p><strong><?=esc_html__ ('Versión:', 'zentrygate');?></strong>
               <?=esc_html (ZENTRYGATE_VERSION_PLUGIN);?></p>
            <p><?=esc_html__ ('Este plugin permite gestionar reservas para eventos con control de aforo, secciones, reglas condicionales y validación de usuarios registrados.', 'zentrygate');?></p>

            <div class="notice notice-warning" style="margin-top:16px;">
                <p><strong><?=esc_html__ ('¡Peligro!', 'zentrygate');?></strong>
                <?=esc_html__ ('Esta acción borrará TODAS las tablas del plugin y las volverá a crear. Perderás los datos existentes.', 'zentrygate');?></p>
            </div>

            <form method="post" style="margin-top: 12px;">
                <?php

		wp_nonce_field ('zg_recreate_db_action', 'zg_recreate_db_nonce');
		?>
                <input
                    type="submit"
                    name="zg_recreate_db"
                    class="button button-primary"
                    value="<?php

		echo esc_attr__ ('Recrear base de datos (BORRAR TODO)', 'zentrygate');
		?>"
                    onclick="return confirm('<?php

		echo esc_js (__ ('¿Seguro que quieres borrar y recrear todas las tablas? Esta acción es irreversible.', 'zentrygate'));
		?>');"
                >
            </form>
        </div>
        <?php
	}


	public static function renderDashboardOld ()
	{
		if (! current_user_can ('manage_options'))
		{
			wp_die (esc_html__ ('No tienes permisos suficientes.', 'zentrygate'));
		}

		global $wpdb;
		$usersTable = $wpdb->prefix . 'zgUsers';
		$eventsTable = $wpdb->prefix . 'zgEvents';
		$reservationsTable = $wpdb->prefix . 'zgReservations';

		// Procesar eliminación si se ha enviado el formulario
		if (isset ($_POST ['zg_purge_old_users']))
		{
			// Verificar nonce (CSRF)
			check_admin_referer ('zg_purge_old_users_action', 'zg_purge_old_users_nonce');

			// Obtener IDs de eventos pasados
			$pastEventIds = $wpdb->get_col ("SELECT id FROM {$eventsTable} WHERE date < CURDATE()");

			if (! empty ($pastEventIds))
			{
				// Eliminar reservas de esos eventos
				$placeholders = implode (',', array_fill (0, count ($pastEventIds), '%d'));
				$wpdb->query ($wpdb->prepare ("DELETE FROM {$reservationsTable} WHERE eventId IN ($placeholders)", ...$pastEventIds));

				// Emails de usuarios NO admin sin reservas en eventos futuros
				$emailsToDelete = $wpdb->get_col ("
                    SELECT u.email
                    FROM {$usersTable} u
                    LEFT JOIN {$reservationsTable} r ON u.email = r.userEmail
                    LEFT JOIN {$eventsTable} e ON r.eventId = e.id
                    WHERE u.isAdmin = 0
                    GROUP BY u.email
                    HAVING MAX(e.date) IS NULL OR MAX(e.date) < CURDATE()
                ");

				if (! empty ($emailsToDelete))
				{
					$ph = implode (',', array_fill (0, count ($emailsToDelete), '%s'));
					$wpdb->query ($wpdb->prepare ("DELETE FROM {$usersTable} WHERE email IN ($ph)", ...$emailsToDelete));
					echo "<div class='notice notice-success'><p>" . esc_html__ ('Usuarios antiguos eliminados.', 'zentrygate') . "</p></div>";
				}
				else
				{
					echo "<div class='notice notice-info'><p>" . esc_html__ ('No hay usuarios antiguos que eliminar.', 'zentrygate') . "</p></div>";
				}
			}
			else
			{
				echo "<div class='notice notice-info'><p>" . esc_html__ ('No hay eventos antiguos registrados.', 'zentrygate') . "</p></div>";
			}
		}
		?>
        <div class="wrap">
            <h1>ZentryGate</h1>
            <p><strong><?=esc_html__ ('Versión:', 'zentrygate');?></strong>
               <?=esc_html (ZENTRYGATE_VERSION_PLUGIN);?></p>
            <p><?=esc_html__ ('Este plugin permite gestionar reservas para eventos con control de aforo, secciones, reglas condicionales y validación de usuarios registrados.', 'zentrygate');?></p>

            <form method="post" style="margin-top:20px;">
                <?php

		wp_nonce_field ('zg_purge_old_users_action', 'zg_purge_old_users_nonce');
		?>
                <input type="submit" name="zg_purge_old_users" class="button-secondary"
                       value="<?=esc_attr__ ('Eliminar usuarios de eventos antiguos', 'zentrygate');?>">
            </form>
        </div>
        <?php
	}


	// --------------------------------------------------------------
	// Textos a mostrar en los plugins de ZentryGate
	// --------------------------------------------------------------
	public static function registerFormTextsSettings (): void
	{
		// Antes: zg_register_form_texts_settings (hook admin_init)
		register_setting ('zg_form_texts_group', 'zg_cookie_prompt_page');
		register_setting ('zg_form_texts_group', 'zg_recovery_form_page');
		register_setting ('zg_form_texts_group', 'zg_login_form_page');

		add_settings_section ('zg_form_texts_section', __ ('Seleccione la página con el texto de cada formulario', 'zentrygate'), '__return_false', 'zentrygate_form_texts');

		$fields = [ 'zg_cookie_prompt_page' => __ ('Cookie Prompt', 'zentrygate'), 'zg_recovery_form_page' => __ ('Recovery Form', 'zentrygate'), 'zg_login_form_page' => __ ('Login Form', 'zentrygate')];

		foreach ($fields as $option => $label)
		{
			add_settings_field ($option, $label, function () use ( $option)
			{
				$current = get_option ($option);
				wp_dropdown_pages ([ 'name' => $option, 'show_option_none' => '— ' . __ ('Selecciona una página', 'zentrygate') . ' —', 'selected' => $current, 'option_none_value' => '0']);
			}, 'zentrygate_form_texts', 'zg_form_texts_section');
		}
	}


	public static function renderFormTexts (): void
	{
		if (! current_user_can ('manage_options'))
		{
			wp_die (esc_html__ ('No tienes permisos suficientes.', 'zentrygate'));
		}
		?>
        <div class="wrap">
            <h1><?=esc_html__ ('Textos de Formularios', 'zentrygate');?></h1>
            <form method="post" action="options.php">
                <?php
		settings_fields ('zg_form_texts_group');
		do_settings_sections ('zentrygate_form_texts');
		submit_button (__ ('Guardar cambios', 'zentrygate'));
		?>
            </form>
        </div>
        <?php
	}


	public static function renderUsers ()
	{ /* ... */
	}


	public static function renderEvents ()
	{ /* ... */
	}
}

