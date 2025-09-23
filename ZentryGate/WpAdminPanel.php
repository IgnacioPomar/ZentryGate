<?php

namespace ZentryGate;

class WpAdminPanel
{


	public static function registerMenus ()
	{
		add_menu_page ('ZentryGate Admin', 'ZentryGate', 'manage_options', 'zentrygate', [ \ZentryGate\AdminPanel\Dashboard::class, 'render'], 'dashicons-groups');
		add_submenu_page ('zentrygate', 'Usuarios Admin', 'Usuarios', 'manage_options', 'zentrygate_users', [ \ZentryGate\AdminPanel\Users::class, 'renderUsers']);
		add_submenu_page ('zentrygate', 'Gestión de Eventos', 'Eventos', 'manage_options', 'zentrygate_events', [ \ZentryGate\AdminPanel\Events::class, 'renderEvents']);
		add_submenu_page ('zentrygate', 'Textos Formularios', 'Textos Formularios', 'manage_options', 'zentrygate_form_texts', [ \ZentryGate\AdminPanel\Texts::class, 'renderFormTexts']);
	}


	// --------------------------------------------------------------
	// Users Page
	// --------------------------------------------------------------
	public static function renderUsers ()
	{
		if (! current_user_can ('manage_options'))
		{
			wp_die (esc_html__ ('No tienes permisos suficientes.', 'zentrygate'));
		}

		// Acciones “rápidas” (crear, reset pass, habilitar/deshabilitar)
		self::handleUserActions ();

		echo '<div class="wrap"><h2>ZentryGate - ' . esc_html__ ('Usuarios', 'zentrygate') . '</h2>';

		// ¿Edición individual?
		if (isset ($_GET ['action']) && $_GET ['action'] === 'edit' && ! empty ($_GET ['email']))
		{
			self::renderEditUserForm (sanitize_email (wp_unslash ($_GET ['email'])));
		}
		else
		{
			self::renderCreateAdminForm ();
			self::listCurrentAdmins ();
			self::listDisabledAdmins ();
		}

		echo '</div>';
	}


	/**
	 * Acciones rápidas en la misma vista (crear admin, reset, enable/disable)
	 */
	private static function handleUserActions (): void
	{
		if (! current_user_can ('manage_options')) return;

		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';

		// Crear nuevo administrador
		if (isset ($_POST ['zg_add_user']))
		{
			check_admin_referer ('zg_add_user_action', 'zg_add_user_nonce');

			$email = sanitize_email (wp_unslash ($_POST ['email'] ?? ''));
			$name = sanitize_text_field (wp_unslash ($_POST ['name'] ?? ''));

			if ($email && $name)
			{
				global $wpdb;
				$table = $wpdb->prefix . 'zgUsers';

				$password = wp_generate_password (10, true, false);
				$hash = password_hash ($password, PASSWORD_DEFAULT);
				$now = current_time ('mysql');

				// Datos mínimos
				$data = [ 'email' => $email, 'name' => $name, 'isAdmin' => 1, 'isEnabled' => 1, 'passwordHash' => $hash, 'status' => 'active', 'otherData' => '{}', 'createdAt' => $now];

				$ok = $wpdb->insert ($table, $data);

				if ($ok === false)
				{
					// Mostrar motivo del fallo
					echo '<div class="notice notice-error"><p><strong>MySQL error:</strong> ' . esc_html ($wpdb->last_error) . '</p></div>';
				}
				else
				{
					echo '<div class="notice notice-success"><p>' . esc_html__ ('Administrador creado con contraseña: ', 'zentrygate') . '<code>' . esc_html ($password) . '</code></p></div>';
				}
			}
		}

		// Cambiar contraseña de administrador
		if (isset ($_POST ['zg_reset_password']))
		{
			check_admin_referer ('zg_reset_password_action', 'zg_reset_password_nonce');

			$email = sanitize_email (wp_unslash ($_POST ['email'] ?? ''));
			if ($email)
			{
				$newPwd = wp_generate_password (10, true, false);
				$hash = password_hash ($newPwd, PASSWORD_DEFAULT);

				$wpdb->update ($table, [ 'passwordHash' => $hash], [ 'email' => $email, 'isAdmin' => 1]);

				echo '<div class="notice notice-success"><p>' . esc_html__ ('Contraseña para ', 'zentrygate') . esc_html ($email) . ': <code>' . esc_html ($newPwd) . '</code></p></div>';
			}
		}

		// Deshabilitar administrador
		if (isset ($_POST ['zg_disable_user']))
		{
			check_admin_referer ('zg_disable_user_action', 'zg_disable_user_nonce');

			$email = sanitize_email (wp_unslash ($_POST ['email'] ?? ''));
			if ($email)
			{
				$wpdb->update ($table, [ 'isEnabled' => 0], [ 'email' => $email, 'isAdmin' => 1]);
				echo '<div class="notice notice-warning"><p>' . esc_html__ ('Administrador deshabilitado: ', 'zentrygate') . esc_html ($email) . '</p></div>';
			}
		}

		// Habilitar administrador
		if (isset ($_POST ['zg_enable_user']))
		{
			check_admin_referer ('zg_enable_user_action', 'zg_enable_user_nonce');

			$email = sanitize_email (wp_unslash ($_POST ['email'] ?? ''));
			if ($email)
			{
				$wpdb->update ($table, [ 'isEnabled' => 1], [ 'email' => $email, 'isAdmin' => 1]);
				echo '<div class="notice notice-success"><p>' . esc_html__ ('Administrador habilitado: ', 'zentrygate') . esc_html ($email) . '</p></div>';
			}
		}
	}


	/**
	 * Formulario: Crear nuevo admin
	 */
	private static function renderCreateAdminForm (): void
	{
		?>
        <h3><?php

		echo esc_html__ ('Crear nuevo administrador', 'zentrygate');
		?></h3>
        <form method="post" style="margin-bottom:30px;">
            <?php

		wp_nonce_field ('zg_add_user_action', 'zg_add_user_nonce');
		?>
            <input type="email" name="email" placeholder="Email" required>
            <input type="text" name="name" placeholder="Nombre" required>
            <button type="submit" name="zg_add_user" class="button button-primary" title="<?php

		echo esc_attr__ ('Crear administrador', 'zentrygate');
		?>">➕</button>
        </form>
        <?php
	}


	/**
	 * Listado: Administradores activos
	 */
	private static function listCurrentAdmins (): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';
		$users = $wpdb->get_results ($wpdb->prepare ("SELECT * FROM {$table} WHERE isAdmin = %d AND isEnabled = %d ORDER BY name", 1, 1));
		?>
        <h3><?php

		echo esc_html__ ('Administradores Activos', 'zentrygate');
		?></h3>
        <table class="widefat fixed striped">
            <thead><tr><th><?=esc_html_e ('Nombre', 'zentrygate');?></th><th><?=esc_html_e ('Email', 'zentrygate');?></th><th><?=esc_html_e ('Acciones', 'zentrygate');?></th></tr></thead>
            <tbody>
            <?php

		foreach (($users ?? [ ]) as $u)
		:
			?>
                <tr>
                    <td><?php

			echo esc_html ($u->name);
			?></td>
                    <td><?php

			echo esc_html ($u->email);
			?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php

			wp_nonce_field ('zg_reset_password_action', 'zg_reset_password_nonce');
			?>
                            <input type="hidden" name="email" value="<?php

			echo esc_attr ($u->email);
			?>">
                            <button type="submit" name="zg_reset_password" class="button" title="<?php

			esc_attr_e ('Cambiar contraseña', 'zentrygate');
			?>">🔑</button>
                        </form>

                        <form method="post" style="display:inline;">
                            <?php

			wp_nonce_field ('zg_disable_user_action', 'zg_disable_user_nonce');
			?>
                            <input type="hidden" name="email" value="<?php

			echo esc_attr ($u->email);
			?>">
                            <button type="submit" name="zg_disable_user" class="button" title="<?php

			esc_attr_e ('Deshabilitar', 'zentrygate');
			?>">🚫</button>
                        </form>

                        <a href="<?php

			echo esc_url (admin_url ('admin.php?page=zentrygate_users&action=edit&email=' . urlencode ($u->email)));
			?>"
                           class="button" title="<?php

			esc_attr_e ('Editar administrador', 'zentrygate');
			?>">✏️</a>
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
	 * Listado: Administradores deshabilitados (recientes)
	 */
	private static function listDisabledAdmins (): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';
		$users = $wpdb->get_results ($wpdb->prepare ("SELECT * FROM {$table} WHERE isAdmin = %d AND isEnabled = %d ORDER BY lastLogin DESC LIMIT 10", 1, 0));
		?>
        <h3><?php

		echo esc_html__ ('Administradores Deshabilitados Recientes', 'zentrygate');
		?></h3>
        <table class="widefat fixed striped">
            <thead><tr><th><?=esc_html_e ('Nombre', 'zentrygate');?></th><th><?=esc_html_e ('Email', 'zentrygate');?></th><th><?=esc_html_e ('Acción', 'zentrygate');?></th></tr></thead>
            <tbody>
            <?php

		foreach (($users ?? [ ]) as $u)
		{
			?>
                <tr>
                    <td><?=esc_html ($u->name);?></td>
                    <td><?=esc_html ($u->email);?></td>
                    <td>
                        <form method="post">
                            <?=wp_nonce_field ('zg_enable_user_action', 'zg_enable_user_nonce');?>
                            <input type="hidden" name="email" value="<?=esc_attr ($u->email);?>">
                            <button type="submit" name="zg_enable_user" class="button" title="<?=esc_attr_e ('Habilitar', 'zentrygate');?>">✅</button>
                        </form>
                    </td>
                </tr>
            <?php
		}
		?>
            </tbody>
        </table>
        <?php
	}


	/**
	 * Formulario edición admin + handler admin_post
	 */
	private static function renderEditUserForm (string $email): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$table} WHERE email = %s AND isAdmin = %d", $email, 1));

		if (! $user)
		{
			echo '<div class="notice notice-error"><p>' . esc_html__ ('Administrador no encontrado.', 'zentrygate') . '</p></div>';
			return;
		}
		?>
        <h3><?php

		echo esc_html__ ('Modificando Administrador: ', 'zentrygate') . esc_html ($user->email);
		?></h3>
        <form action="<?php

		echo esc_url (admin_url ('admin-post.php'));
		?>" method="post">
            <?php

		wp_nonce_field ('zg_edit_user_action', 'zg_edit_user_nonce');
		?>
            <input type="hidden" name="action" value="zg_edit_user">
            <input type="hidden" name="original_email" value="<?php

		echo esc_attr ($user->email);
		?>">

            <table class="form-table">
                <tr>
                    <th><label for="email"><?php

		esc_html_e ('Email', 'zentrygate');
		?></label></th>
                    <td><input type="email" id="email" name="email" value="<?php

		echo esc_attr ($user->email);
		?>" required></td>
                </tr>
                <tr>
                    <th><label for="name"><?php

		esc_html_e ('Nombre', 'zentrygate');
		?></label></th>
                    <td><input type="text" id="name" name="name" value="<?php

		echo esc_attr ($user->name);
		?>" required></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary" title="<?php

		esc_attr_e ('Guardar cambios', 'zentrygate');
		?>">💾</button>
                <a href="<?php

		echo esc_url (admin_url ('admin.php?page=zentrygate_users'));
		?>" class="button" title="<?php

		esc_attr_e ('Cancelar', 'zentrygate');
		?>">✖️</a>
            </p>
        </form>
        <?php
	}


	/**
	 * admin-post handler para guardar edición
	 */
	public static function processEditUser (): void
	{
		if (! current_user_can ('manage_options'))
		{
			wp_die (esc_html__ ('No tienes permisos suficientes.', 'zentrygate'));
		}
		check_admin_referer ('zg_edit_user_action', 'zg_edit_user_nonce');

		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';
		$orig = sanitize_email (wp_unslash ($_POST ['original_email'] ?? ''));
		$emailNew = sanitize_email (wp_unslash ($_POST ['email'] ?? ''));
		$nameNew = sanitize_text_field (wp_unslash ($_POST ['name'] ?? ''));

		if ($orig && $emailNew && $nameNew)
		{
			$wpdb->update ($table, [ 'email' => $emailNew, 'name' => $nameNew], [ 'email' => $orig, 'isAdmin' => 1]);
		}

		wp_redirect (admin_url ('admin.php?page=zentrygate_users'));
		exit ();
	}


	public static function renderEvents ()
	{ /* ... */
	}
}

