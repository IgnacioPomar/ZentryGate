<?php

namespace ZentryGate;

/**
 * ZentryGate Plugin Class
 *
 * Handles the addition of a custom page template and its loading.
 */
class Plugin
{


	public function __construct ()
	{
		add_filter ('theme_page_templates', [ $this, 'add_page_template']);
		add_filter ('template_include', [ $this, 'load_page_template']);

		// Work with cookies and sessions
		add_action ('init', [ Auth::class, 'init']);
	}


	public function add_page_template ($templates)
	{
		$templates ['template-zentrygate.php'] = 'Contenido + ZentryGate';
		return $templates;
	}


	public function load_page_template ($template)
	{
		if (is_page ())
		{
			global $post;
			$slug = get_page_template_slug ($post->ID);
			if ('template-zentrygate.php' === $slug)
			{

				if (! wp_style_is ('zentrygate-styles', 'enqueued'))
				{
					wp_enqueue_style ('zentrygate-styles', plugin_dir_url (__FILE__) . '../rsc/zentrygate.css', [ ], '1.0');
				}

				$file = ZENTRYGATE_PLUGIN_DIR . 'templates/template-zentrygate.php';
				if (file_exists ($file))
				{
					return $file;
				}
			}
		}
		return $template;
	}
}