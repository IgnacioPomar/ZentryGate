<?php

/**
 * admin/users.php
 * Gestión de usuarios administradores para ZentryGate
 */

// Hook principal para renderizar la página
function zg_render_users_page()
{
    // Procesar acciones 'quick' antes de render
    zg_handle_user_actions();

    echo '<div class="wrap"><h2>ZentryGate - Usuarios</h2>';

    // Si estamos editando, mostrar solo el formulario de edición
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && ! empty($_GET['email'])) {
        zg_render_edit_user_form(sanitize_email($_GET['email']));
    } else {
        // Secciones estándar
        zg_render_create_admin_form();
        zg_list_current_admins();
        zg_list_disabled_admins();
    }

    echo '</div>';
}

// ------------------------
// Manejador de acciones
// (sin wp_redirect para evitar headers sent)
// ------------------------
function zg_handle_user_actions()
{
    global $wpdb;
    $table = $wpdb->prefix . 'zgUsers';

    // Crear nuevo administrador
    if (isset($_POST['zg_add_user'])) {
        $email = sanitize_email($_POST['email']);
        $name = sanitize_text_field($_POST['name']);
        $password = wp_generate_password(10, true, false);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $wpdb->insert($table, [
            'email' => $email,
            'name' => $name,
            'invitationCount' => 0,
            'isAdmin' => 1,
            'isEnabled' => 1,
            'passwordHash' => $hash
        ]);
        echo '<div class="notice notice-success"><p>Administrador creado con contraseña: <code>' . esc_html($password) . '</code></p></div>';
    }

    // Cambiar contraseña de administrador
    if (isset($_POST['zg_reset_password'])) {
        $email = sanitize_email($_POST['email']);
        $newPwd = wp_generate_password(10, true, false);
        $hash = password_hash($newPwd, PASSWORD_DEFAULT);
        $wpdb->update($table, [
            'passwordHash' => $hash
        ], [
            'email' => $email
        ]);
        echo '<div class="notice notice-success"><p>Contraseña para ' . esc_html($email) . ": <code>$newPwd</code></p></div>";
    }

    // Deshabilitar administrador
    if (isset($_POST['zg_disable_user'])) {
        $email = sanitize_email($_POST['email']);
        $wpdb->update($table, [
            'isEnabled' => 0
        ], [
            'email' => $email,
            'isAdmin' => 1
        ]);
        echo '<div class="notice notice-warning"><p>Administrador deshabilitado: ' . esc_html($email) . '</p></div>';
    }

    // Habilitar administrador
    if (isset($_POST['zg_enable_user'])) {
        $email = sanitize_email($_POST['email']);
        $wpdb->update($table, [
            'isEnabled' => 1
        ], [
            'email' => $email,
            'isAdmin' => 1
        ]);
        echo '<div class="notice notice-success"><p>Administrador habilitado: ' . esc_html($email) . '</p></div>';
    }
}

// --------------------------------
// Formulario: Crear administrador
// --------------------------------
function zg_render_create_admin_form()
{
    ?>
    <h3>Crear nuevo administrador</h3>
    <form method="post" style="margin-bottom:30px;">
        <input type="email" name="email" placeholder="Email" required>
        <input type="text" name="name" placeholder="Nombre" required>
        <button type="submit" name="zg_add_user" class="button button-primary" title="Crear administrador">➕</button>
    </form>
    <?php
}

// --------------------------------
// Listado: Administradores activos
// --------------------------------
function zg_list_current_admins()
{
    global $wpdb;
    $table = $wpdb->prefix . 'zgUsers';
    $users = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE isAdmin = %d AND isEnabled = %d ORDER BY name", 1, 1));
    ?>
    <h3>Administradores Activos</h3>
    <table class="widefat fixed striped">
        <thead><tr><th>Nombre</th><th>Email</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php

foreach ($users as $u) :
        ?>
            <tr>
                <td><?php

echo esc_html($u->name);
        ?></td>
                <td><?php

echo esc_html($u->email);
        ?></td>
                <td>
                    <form method="post" style="display:inline;"><input type="hidden" name="email" value="<?php

echo esc_attr($u->email);
        ?>"><button type="submit" name="zg_reset_password" class="button" title="Cambiar contraseña">🔑</button></form>
                    <form method="post" style="display:inline;"><input type="hidden" name="email" value="<?php

echo esc_attr($u->email);
        ?>"><button type="submit" name="zg_disable_user" class="button" title="Deshabilitar">🚫</button></form>
                    <a href="<?php

echo admin_url('admin.php?page=zentrygate_users&action=edit&email=' . urlencode($u->email));
        ?>" class="button" title="Editar administrador">✏️</a>
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

// --------------------------------
// Listado: Administradores deshabilitados
// --------------------------------
function zg_list_disabled_admins()
{
    global $wpdb;
    $table = $wpdb->prefix . 'zgUsers';
    $users = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE isAdmin = %d AND isEnabled = %d ORDER BY lastLogin DESC LIMIT 10", 1, 0));
    ?>
    <h3>Administradores Deshabilitados Recientes</h3>
    <table class="widefat fixed striped">
        <thead><tr><th>Nombre</th><th>Email</th><th>Acción</th></tr></thead>
        <tbody>
        <?php

foreach ($users as $u) :
        ?>
            <tr>
                <td><?php

echo esc_html($u->name);
        ?></td>
                <td><?php

echo esc_html($u->email);
        ?></td>
                <td><form method="post"><input type="hidden" name="email" value="<?php

echo esc_attr($u->email);
        ?>"><button type="submit" name="zg_enable_user" class="button" title="Habilitar">✅</button></form></td>
            </tr>
        <?php

endforeach
    ;
    ?>
        </tbody>
    </table>
    <?php
}

// --------------------------------
// Formulario: Editar administrador
// y procesamiento via admin_post
// --------------------------------
add_action('admin_post_zg_edit_user', 'zg_process_edit_user');

function zg_render_edit_user_form($email)
{
    global $wpdb;
    $table = $wpdb->prefix . 'zgUsers';
    $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email = %s AND isAdmin = %d", $email, 1));
    if (! $user) {
        echo '<div class="notice notice-error"><p>Administrador no encontrado.</p></div>';
        return;
    }
    ?>
    <h3>Modificando Administrador: <?php

echo esc_html($user->email);
    ?></h3>
    <form action="<?php

echo admin_url('admin-post.php');
    ?>" method="post">
        <input type="hidden" name="action" value="zg_edit_user">
        <input type="hidden" name="original_email" value="<?php

echo esc_attr($user->email);
    ?>">
        <table class="form-table">
            <tr><th><label for="email">Email</label></th><td><input type="email" id="email" name="email" value="<?php

echo esc_attr($user->email);
    ?>" required></td></tr>
            <tr><th><label for="name">Nombre</label></th><td><input type="text" id="name" name="name" value="<?php

echo esc_attr($user->name);
    ?>" required></td></tr>
        </table>
        <p class="submit">
            <button type="submit" class="button button-primary" title="Guardar cambios">💾</button>
            <a href="<?php

echo admin_url('admin.php?page=zentrygate_users');
    ?>" class="button" title="Cancelar">✖️</a>
        </p>
    </form>
    <?php
}

function zg_process_edit_user()
{
    global $wpdb;
    $table = $wpdb->prefix . 'zgUsers';
    $orig = sanitize_email($_POST['original_email']);
    $emailNew = sanitize_email($_POST['email']);
    $nameNew = sanitize_text_field($_POST['name']);
    $wpdb->update($table, [
        'email' => $emailNew,
        'name' => $nameNew
    ], [
        'email' => $orig,
        'isAdmin' => 1
    ]);
    wp_redirect(admin_url('admin.php?page=zentrygate_users'));
    exit();
}
