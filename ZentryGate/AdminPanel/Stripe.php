<?php

namespace ZentryGate\AdminPanel;

class Stripe
{
	private static string $optionKey = 'zentrygate_stripe_settings';


	// YAGNI: almacenar si es live o final, y permite guardar ambas claves

	/**
	 * Renderiza la página de configuración de Stripe en wp-admin
	 */
	public static function render (): void
	{
		// Guardar cambios si el formulario fue enviado
		if ($_SERVER ['REQUEST_METHOD'] === 'POST' && isset ($_POST ['_wpnonce']) && wp_verify_nonce ($_POST ['_wpnonce'], 'zentrygate_stripe_save'))
		{
			$publishable = sanitize_text_field ($_POST ['zg_stripe_publishable'] ?? '');
			$secret = sanitize_text_field ($_POST ['zg_stripe_secret'] ?? '');

			update_option (self::$optionKey, [ 'publishable' => $publishable, 'secret' => $secret]);

			echo '<div class="updated"><p>Datos de Stripe guardados correctamente.</p></div>';
		}

		// Obtener valores actuales
		$values = get_option (self::$optionKey, [ 'publishable' => '', 'secret' => '']);

		self::checkDependecncies ();

		?>
		
        <div class="wrap">
            <h1>Configurar Stripe</h1>
            <form method="post" action="">
                <?php

		wp_nonce_field ('zentrygate_stripe_save');
		?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="zg_stripe_publishable">Clave publicable</label></th>
                        <td>
                            <input type="text" name="zg_stripe_publishable" id="zg_stripe_publishable"
                                   value="<?php

		echo esc_attr ($values ['publishable']);
		?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zg_stripe_secret">Clave secreta</label></th>
                        <td>
                            <input type="password" name="zg_stripe_secret" id="zg_stripe_secret"
                                   value="<?php

		echo esc_attr ($values ['secret']);
		?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                </table>

                <?php

		submit_button ('Guardar cambios');
		?>
            </form>
        </div>
        <?php
	}


	private static function checkDependecncies (): void
	{
		$extensions = [ 'curl' => 'Requerido para llamadas HTTP a la API de Stripe', 'json' => 'Requerido para codificar/decodificar datos', 'mbstring' => 'Requerido para strings multibyte (UTF-8)', 'openssl' => 'Requerido para conexiones TLS seguras', 'gmp' => 'Opcional, ayuda con enteros grandes',
				'bcmath' => 'Opcional, ayuda con enteros grandes'];

		echo '<div class="wrap">';
		echo '<h1>Chequeo de extensiones PHP para Stripe</h1>';
		echo '<table class="widefat fixed striped" style="max-width:600px">';
		echo '<thead><tr><th>Extensión</th><th>Estado</th><th>Comentario</th></tr></thead><tbody>';

		foreach ($extensions as $ext => $desc)
		{
			$loaded = extension_loaded ($ext);
			$icon = $loaded ? '<span style="color:green;font-weight:bold">✅ Cargada</span>' : '<span style="color:red;font-weight:bold">❌ No cargada</span>';
			echo '<tr>';
			echo '<td><code>' . esc_html ($ext) . '</code></td>';
			echo '<td>' . $icon . '</td>';
			echo '<td>' . esc_html ($desc) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><em>Nota:</em> Stripe requiere <code>curl</code>, <code>json</code>, <code>mbstring</code> y <code>openssl</code>. <code>gmp</code> y <code>bcmath</code> son opcionales.</p>';
		echo '</div>';
	}


	/**
	 * Devuelve la clave publicable
	 */
	public static function getPublishableKey (): string
	{
		$values = get_option (self::$optionKey, [ ]);
		return $values ['publishable'] ?? '';
	}


	/**
	 * Devuelve la clave secreta
	 */
	public static function getSecretKey (): string
	{
		$values = get_option (self::$optionKey, [ ]);
		return $values ['secret'] ?? '';
	}
}
