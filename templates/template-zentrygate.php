<?php
/**
 * Template Name: Contenido + ZentryGate
 * Description: Shows the page content and appends the plugin form.
 */
defined ('ABSPATH') || exit ();

require_once ZENTRYGATE_PLUGIN_DIR . 'ZentryGate/Auth.php';
require_once ZENTRYGATE_PLUGIN_DIR . 'ZentryGate/PageRenderer.php';

use ZentryGate\PageRenderer;

get_header ();

echo '<div id="ZentryGate-main" class="container">';
if (have_posts ())
{
	while (have_posts ())
	{
		the_post ();
		the_content ();
	}
}
$renderer = new PageRenderer ();
$renderer->renderPluginPageContents ();

echo '</div>';

get_footer ();


