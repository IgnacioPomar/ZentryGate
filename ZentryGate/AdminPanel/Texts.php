<?php

namespace ZentryGate\AdminPanel;

class Texts
{


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
}

