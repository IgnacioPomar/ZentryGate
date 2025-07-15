<?php

namespace ZentryGate;

class AdministratorPage
{
	private array $sessionData;
	private string $action;


	public function __construct (array $sessionData)
	{
		$this->sessionData = $sessionData;
		// Leemos la acción solicitada (por defecto, dashboard)
		$this->action = isset ($_GET ['zg_action']) ? sanitize_key ($_GET ['zg_action']) : 'dashboard';
	}


	public function render (): void
	{
		echo '<div class="wrap zg-admin-page">';
		echo '<h1>' . esc_html__ ('Panel de Administración', 'zentrygate') . '</h1> <div class="zg-admin-content">';

		// Menú lateral de opciones
		$this->renderAdminMenu ();

		// Contenido según la acción solicitada
		switch ($this->action)
		{
			case 'import':
				$this->processImport ();
				$this->renderImportForm ();
				break;
			case 'manage_user':
				$this->processManageUser ();
				$this->renderManageUser ();
				break;
			case 'add_user':
				$this->processAddUser ();
				$this->renderAddUser ();
				break;
			case 'export_csv':
				// $this->processExportCSV ();
				$this->renderExportCSV ();
				break;
			case 'dashboard':
			default:
				$this->renderDashboard ();
				break;
		}

		echo '</div></div>'; // Cierre de wrap y zg-admin-content
	}


	private function renderAdminMenu (): void
	{
		$tabs = [ 'dashboard' => __ ('Inicio', 'zentrygate'), 'import' => __ ('Importar usuarios', 'zentrygate'), 'manage_user' => __ ('Gestionar usuarios', 'zentrygate'), 'export_csv' => __ ('Exportar reservas', 'zentrygate')];

		echo '<h2 class="nav-tab-wrapper">';
		foreach ($tabs as $key => $label)
		{
			$class = $this->action === $key ? ' nav-tab-active' : '';
			$url = esc_url (add_query_arg ('zg_action', $key));
			printf ('<a href="%s" class="nav-tab%s">%s</a>', $url, $class, esc_html ($label));
		}
		echo '</h2>';
	}


	/**
	 * 0.
	 * Saludo inicial
	 */
	private function renderDashboard (): void
	{
		$name = isset ($this->sessionData ['name']) ? sanitize_text_field ($this->sessionData ['name']) : '';
		printf ('<h2>%s, %s</h2>', esc_html__ ('Bienvenido', 'zentrygate'), esc_html ($name));
		echo '<p>' . esc_html__ ('Desde aquí puedes gestionar las diferentes opciones de administración.', 'zentrygate') . '</p>';
	}


	/**
	 * 1.
	 * Importar usuarios desde fichero ASCII
	 */
	private function renderImportForm (): void
	{
		?>
        <div class="zg-box">
            <h2><?=esc_html__ ('Importar usuarios desde archivo', 'zentrygate')?></h2>
        <form method="post" enctype="multipart/form-data">
            <?=wp_nonce_field ('zg_import_action', 'zg_import_nonce', true, false)?>
            <label for="zg_import_file"><?=esc_html__ ('Archivo .txt/.csv (email,nombre,pass):', 'zentrygate')?></label><br>
            <input type="file" id="zg_import_file" name="zg_import_file" accept=".txt,.csv" required>
            <p><button type="submit" name="zg_do_import" class="button button-primary"><?=esc_html__ ('Importar ahora', 'zentrygate')?></button></p>
        </form>
        </div>
        <?php
	}


	private function processImport (): void
	{
		if (! isset ($_POST ['zg_do_import']) || ! check_admin_referer ('zg_import_action', 'zg_import_nonce'))
		{
			return;
		}

		if (empty ($_FILES ['zg_import_file']) || $_FILES ['zg_import_file'] ['error'] !== UPLOAD_ERR_OK)
		{
			echo '<div class="notice notice-error"><p>' . esc_html__ ('Error al subir el archivo.', 'zentrygate') . '</p></div>';
			return;
		}

		$file = fopen ($_FILES ['zg_import_file'] ['tmp_name'], 'r');
		if (! $file)
		{
			echo '<div class="notice notice-error"><p>' . esc_html__ ('No se pudo abrir el archivo.', 'zentrygate') . '</p></div>';
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';
		$imported = 0;
		$duplicates = [ ];

		while (($data = fgetcsv ($file, 1000, ',')) !== false)
		{
			if (count ($data) < 3)
			{
				continue;
			}
			list ($email, $name, $pass) = array_map ('sanitize_text_field', $data);
			if (! is_email ($email))
			{
				continue;
			}
			$hash = wp_hash_password ($pass);
			$result = $wpdb->insert ($table, [ 'email' => $email, 'name' => $name, 'passwordHash' => $hash, 'isAdmin' => 0, 'isEnabled' => 1, 'invitationCount' => 0, 'lastLogin' => null], [ '%s', '%s', '%s', '%d', '%d', '%d', '%s']);
			if ($result === false)
			{
				$duplicates [] = $email;
			}
			else
			{
				$imported ++;
			}
		}
		fclose ($file);

		if ($imported)
		{
			echo '<div class="notice notice-success"><p>' . esc_html (sprintf (__ ('Usuarios importados: %d', 'zentrygate'), $imported)) . '</p></div>';
		}
		if (! empty ($duplicates))
		{
			echo '<div class="notice notice-warning"><p>' . esc_html (__ ('Usuarios duplicados (no importados): ', 'zentrygate')) . esc_html (implode (', ', $duplicates)) . '</p></div>';
		}
	}


	/**
	 * 2.
	 * Añadir, modificar o deshabilitar usuarios manualmente
	 */

	/**
	 * Procesa las acciones de gestión de usuario: deshabilitar o actualizar contraseña.
	 */
	private function processManageUser (): void
	{
		if (empty ($_POST ['zg_manage_user_nonce']) || ! check_admin_referer ('zg_manage_user_action', 'zg_manage_user_nonce'))
		{
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';

		// Deshabilitar usuario
		if (! empty ($_POST ['zg_do_disable']) && ! empty ($_POST ['zg_disable_email']))
		{
			$email = sanitize_email (wp_unslash ($_POST ['zg_disable_email']));
			$wpdb->update ($table, [ 'isEnabled' => 0], [ 'email' => $email], [ '%d'], [ '%s']);
			echo '<div class="notice notice-success"><p>' . esc_html__ ('Usuario deshabilitado.', 'zentrygate') . '</p></div>';
		}

		// Actualizar contraseña
		if (! empty ($_POST ['zg_do_update']) && ! empty ($_POST ['zg_update_email']) && ! empty ($_POST ['zg_new_password']))
		{
			$email = sanitize_email (wp_unslash ($_POST ['zg_update_email']));
			$password = sanitize_text_field (wp_unslash ($_POST ['zg_new_password']));
			$hash = wp_hash_password ($password);
			$wpdb->update ($table, [ 'passwordHash' => $hash], [ 'email' => $email], [ '%s'], [ '%s']);
			echo '<div class="notice notice-success"><p>' . esc_html__ ('Contraseña actualizada.', 'zentrygate') . '</p></div>';
		}
	}


	/**
	 * Renderiza buscador y lista paginada de usuarios (isAdmin = 0), con botones de deshabilitar y modificar.
	 */
	private function renderManageUser (): void
	{
		global $wpdb;

		// Parámetros de paginación y búsqueda
		$per_page = 10;
		$paged = max (1, intval ($_GET ['zg_page'] ?? 1));
		$search = sanitize_text_field (wp_unslash ($_GET ['zg_search'] ?? ''));
		$page_slug = sanitize_text_field ($_GET ['page'] ?? '');

		// Construir filtro de búsqueda
		$where = [ 'isAdmin = 0'];
		$params = [ ];
		if ($search !== '')
		{
			$where [] = '( email LIKE %s OR name LIKE %s )';
			$like = '%' . $wpdb->esc_like ($search) . '%';
			$params [] = $like;
			$params [] = $like;
		}
		$where_sql = implode (' AND ', $where);

		// Total de usuarios
		$total = (int) $wpdb->get_var ($wpdb->prepare ("SELECT COUNT(*) FROM {$wpdb->prefix}zgUsers WHERE {$where_sql}", ...$params));

		// Obtención de datos con límite
		$offset = ($paged - 1) * $per_page;
		$rows = $wpdb->get_results ($wpdb->prepare ("SELECT email, name, isEnabled
             FROM {$wpdb->prefix}zgUsers
             WHERE {$where_sql}
             ORDER BY email
             LIMIT %d, %d", ...array_merge ($params, [ $offset, $per_page])));

		// Base URL conservando page y zg_action
		$base_args = [ 'page' => $page_slug, 'zg_action' => 'manage_user'];
		if ($search !== '')
		{
			$base_args ['zg_search'] = $search;
		}

		?>
    <div class="zg-box">
        <h2><?=esc_html__ ('Gestionar usuarios', 'zentrygate')?></h2>

        <!-- Formulario de búsqueda -->
        <form method="get" class="zg-search-form">
            <?php

		foreach ($base_args as $k => $v)
		:
			?>
                <input type="hidden" name="<?=esc_attr ($k)?>" value="<?=esc_attr ($v)?>">
            <?php
		endforeach
		;
		?>
            <input
                type="search"
                name="zg_search"
                value="<?=esc_attr ($search)?>"
                placeholder="<?=esc_attr__ ('Buscar por email o nombre…', 'zentrygate')?>"
            >
            <button type="submit" class="button"><?=esc_html__ ('Buscar', 'zentrygate')?></button>
        </form>

        <!-- Tabla de usuarios -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?=esc_html__ ('Email', 'zentrygate')?></th>
                    <th><?=esc_html__ ('Nombre', 'zentrygate')?></th>
                    <th><?=esc_html__ ('Estado', 'zentrygate')?></th>
                    <th><?=esc_html__ ('Acciones', 'zentrygate')?></th>
                </tr>
            </thead>
            <tbody>
                <?php

		if ($rows)
		:
			?>
                    <?php

			foreach ($rows as $user)
			:
				?>
                    <tr>
                        <td><?=esc_html ($user->email)?></td>
                        <td><?=esc_html ($user->name)?></td>
                        <td>
                            <?=$user->isEnabled ? esc_html__ ('Activo', 'zentrygate') : esc_html__ ('Deshabilitado', 'zentrygate')?>
                        </td>
                        <td>
                            <!-- Deshabilitar -->
                            <form method="post" style="display:inline">
                                <?=wp_nonce_field ('zg_manage_user_action', 'zg_manage_user_nonce', true, false)?>
                                <input type="hidden" name="zg_disable_email" value="<?=esc_attr ($user->email)?>">
                                <button type="submit" name="zg_do_disable" class="button button-secondary">
                                    <?=esc_html__ ('Deshabilitar', 'zentrygate')?>
                                </button>
                            </form>
                            <!-- Modificar -->
                            <?php
				$mod_args = array_merge ($base_args, [ 'zg_page' => $paged, 'zg_user' => $user->email]);
				?>
                            <a
                                href="<?=esc_url (add_query_arg ($mod_args))?>"
                                class="button button-primary"
                            >
                                <?=esc_html__ ('Modificar', 'zentrygate')?>
                            </a>
                        </td>
                    </tr>
                    <?php
			endforeach
			;
			?>
                <?php

		else
		:
			?>
                    <tr>
                        <td colspan="4"><?=esc_html__ ('No se encontraron usuarios.', 'zentrygate')?></td>
                    </tr>
                <?php

		endif;
		?>
            </tbody>
        </table>

        <!-- Paginación -->
        <?php
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links ([ 'base' => add_query_arg ('zg_page', '%#%', add_query_arg ($base_args)), 'format' => '', 'prev_text' => '&laquo;', 'next_text' => '&raquo;', 'total' => ceil ($total / $per_page), 'current' => $paged]);
		echo '</div></div>';
		?>

        <!-- Formulario en línea para cambio de contraseña -->
        <?php

		if (! empty ($_GET ['zg_user']))
		:
			$email = sanitize_email (wp_unslash ($_GET ['zg_user']));
			?>
            <h3><?=sprintf (esc_html__ ('Modificar %s', 'zentrygate'), esc_html ($email))?></h3>
            <form method="post" class="zg-modify-form">
                <?=wp_nonce_field ('zg_manage_user_action', 'zg_manage_user_nonce', true, false)?>
                <input type="hidden" name="zg_update_email" value="<?=esc_attr ($email)?>">
                <p>
                    <label for="zg_new_password"><?=esc_html__ ('Nueva contraseña:', 'zentrygate')?></label><br>
                    <input type="password" id="zg_new_password" name="zg_new_password" required>
                </p>
                <p>
                    <button type="submit" name="zg_do_update" class="button button-primary">
                        <?=esc_html__ ('Actualizar contraseña', 'zentrygate')?>
                    </button>
                </p>
            </form>
        <?php endif;

		?>

    </div>
    <?php
	}


	/**
	 * 3.
	 * Generar CSV de usuarios con reservas
	 */
	private function renderExportCSV (): void
	{
		?>
        <h2><?=esc_html_e ('Exportar usuarios con reservas', 'zentrygate');?></h2>
        <p><?=esc_html_e ('Desde aquí podrás generar y descargar un CSV con todos los usuarios que tienen reservas activas.', 'zentrygate');?></p>
        <form method="post">
            <?=wp_nonce_field ('zg_export_csv_action', 'zg_export_csv_nonce');?>
            <p>
                <button type="submit" name="zg_do_export_csv" class="button button-primary">
                    <?=esc_html_e ('Descargar CSV ahora', 'zentrygate');?>
                </button>
            </p>
        </form>
        <?php
	}
}