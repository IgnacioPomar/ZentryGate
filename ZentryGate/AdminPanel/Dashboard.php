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
		$recreateNotice = '';
		if (isset ($_POST ['zg_recreate_db']))
		{
			check_admin_referer ('zg_recreate_db_action', 'zg_recreate_db_nonce');
			try
			{
				\ZentryGate\Install::recreateDatabase ();
				$recreateNotice = "<div class='notice notice-success'><p>" . esc_html__ ('Base de datos recreada correctamente.', 'zentrygate') . "</p></div>";
			}
			catch (\Throwable $e)
			{
				$recreateNotice = "<div class='notice notice-error'><p>" . esc_html__ ('Error al recrear la base de datos: ', 'zentrygate') . esc_html ($e->getMessage ()) . "</p></div>";
			}
		}
		?>
        <div class="wrap">
            <h1>ZentryGate</h1>
            <?php echo $recreateNotice; ?>
            <div class="zg-admin-column">

                <div class="postbox">
                <h2 class="hndle"><span><?=esc_html__ ('Resumen', 'zentrygate');?></span></h2>
                <div class="inside">
                    <p><strong><?=esc_html__ ('Versión:', 'zentrygate');?></strong>
                       <?=esc_html (ZENTRYGATE_VERSION_PLUGIN);?></p>
                    <p><?=esc_html__ ('Este plugin permite gestionar reservas para eventos con control de aforo, secciones, reglas condicionales y validación de usuarios registrados.', 'zentrygate');?></p>
                </div>
                </div>

                <div class="postbox">
                <h2 class="hndle"><span><?=esc_html__ ('Instrucciones básicas', 'zentrygate');?></span></h2>
                <div class="inside">
                    <ol>
                        <li><?=esc_html__ ('Asigna a una página la plantilla de ZentryGate ("Zentrygate Inscription Form") desde Atributos de página al editarla. Esta plantilla la proporciona el tema activo, no el plugin.', 'zentrygate');?></li>
                        <li><?=esc_html__ ('A partir de ahí, el proceso de inscripción/login continuará en una página independiente, configurada por separado.', 'zentrygate');?></li>
                    </ol>
                </div>
                </div>

                <div class="postbox">
                <h2 class="hndle"><span><?=esc_html__ ('Accesos directos', 'zentrygate');?></span></h2>
                <div class="inside">
                    <p class="zg-button-row">
                        <a class="button button-secondary" href="<?=esc_url (admin_url ('admin.php?page=zentrygate_users'));?>"><?=esc_html__ ('Usuarios', 'zentrygate');?></a>
                        <a class="button button-secondary" href="<?=esc_url (admin_url ('admin.php?page=zentrygate_events'));?>"><?=esc_html__ ('Eventos', 'zentrygate');?></a>
                        <a class="button button-secondary" href="<?=esc_url (admin_url ('admin.php?page=zentrygate_stripe'));?>"><?=esc_html__ ('Stripe', 'zentrygate');?></a>
                        <a class="button button-secondary" href="<?=esc_url (admin_url ('admin.php?page=zentrygate_form_texts'));?>"><?=esc_html__ ('Textos Formularios', 'zentrygate');?></a>
                    </p>
                </div>
                </div>

                <div class="postbox zg-postbox-danger">
                <h2 class="hndle"><span><?=esc_html__ ('Zona de peligro', 'zentrygate');?></span></h2>
                <div class="inside">
                    <div class="notice notice-warning inline" style="margin:0 0 12px;">
                        <p><strong><?=esc_html__ ('¡Peligro!', 'zentrygate');?></strong>
                        <?=esc_html__ ('Esta acción borrará TODAS las tablas del plugin y las volverá a crear. Perderás los datos existentes.', 'zentrygate');?></p>
                    </div>

                    <form method="post">
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
                </div>

            </div>
        </div>
        <?php
	}
}

