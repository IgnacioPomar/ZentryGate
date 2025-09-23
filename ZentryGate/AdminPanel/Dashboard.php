<?php

namespace ZentryGate\AdminPanel;

class Dashboard
{


	public static function getAllEvents (): array
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgEvents';
		// Devuelve array de objetos stdClass como get_results()
		return (array) $wpdb->get_results ("SELECT * FROM {$table} ORDER BY date DESC");
	}


	public static function render (): void
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
				\ZentryGate\Install::recreateDatabase ();
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
                <?=wp_nonce_field ('zg_recreate_db_action', 'zg_recreate_db_nonce');?>
                <input
                    type="submit"
                    name="zg_recreate_db"
                    class="button button-primary"
                    value="<?=esc_attr__ ('Recrear base de datos (BORRAR TODO)', 'zentrygate');?>"
                    onclick="return confirm('<?=esc_js (__ ('¿Seguro que quieres borrar y recrear todas las tablas? Esta acción es irreversible.', 'zentrygate'));?>');"
                >
            </form>
        </div>
        <?php
	}
}

