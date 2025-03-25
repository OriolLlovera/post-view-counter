<?php
/*
Plugin Name: Post and Page View Counter
Description: Estadísticas de visitas con gráficos, filtros, paginación y reseteo
Version: 1.3
Author: Oriol Llovera
*/

defined('ABSPATH') or die('Acceso directo no permitido');

// ==================== FUNCIONES PRINCIPALES ====================

// Registrar visitas
add_action('template_redirect', 'pps_update_view_count');
function pps_update_view_count() {
    if ((is_single() || is_page()) && !is_user_logged_in() && !is_admin()) {
        $post_id = get_the_ID();
        if (!$post_id) return;

        $cookie_name = "pps_visited_" . COOKIEHASH . "_$post_id";
        if (!isset($_COOKIE[$cookie_name])) {
            $total_views = (int)get_post_meta($post_id, 'pps_post_views_count', true);
            update_post_meta($post_id, 'pps_post_views_count', $total_views + 1);
            update_post_meta($post_id, 'pps_last_visit_date', current_time('mysql'));

            $today = date('Y-m-d');
            $month = date('Y-m');
            $year = date('Y');
            update_post_meta($post_id, "pps_daily_views_$today", (int)get_post_meta($post_id, "pps_daily_views_$today", true) + 1);
            update_post_meta($post_id, "pps_monthly_views_$month", (int)get_post_meta($post_id, "pps_monthly_views_$month", true) + 1);
            update_post_meta($post_id, "pps_yearly_views_$year", (int)get_post_meta($post_id, "pps_yearly_views_$year", true) + 1);

            setcookie($cookie_name, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        }
    }
}

// Migración de datos al activar
register_activation_hook(__FILE__, 'pps_migrate_existing_views_data');
function pps_migrate_existing_views_data() {
    global $wpdb;
    $posts = get_posts([
        'post_type' => ['post', 'page'],
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ]);

    foreach ($posts as $post) {
        $post_id = $post->ID;
        $total_views = get_post_meta($post_id, 'post_views_count', true) ?: 
                      get_post_meta($post_id, 'pps_post_views_count', true) ?: 0;
        
        if ($total_views) {
            update_post_meta($post_id, 'pps_post_views_count', $total_views);
            
            $today = date('Y-m-d');
            $month = date('Y-m');
            $year = date('Y');
            update_post_meta($post_id, "pps_daily_views_$today", $total_views);
            update_post_meta($post_id, "pps_monthly_views_$month", $total_views);
            update_post_meta($post_id, "pps_yearly_views_$year", $total_views);
        }
    }
}

// ==================== ADMINISTRACIÓN ====================

// Añadir página de administración
add_action('admin_menu', 'pps_add_view_stats_page');
function pps_add_view_stats_page() {
    add_menu_page(
        'Estadísticas de Visitas',
        'Estadísticas',
        'manage_options',
        'pps-view-stats',
        'pps_render_view_stats_page',
        'dashicons-chart-bar',
        25
    );
}

// Resetear estadísticas
function pps_reset_all_stats() {
    if (!current_user_can('manage_options')) {
        return false;
    }

    global $wpdb;
    
    $meta_keys = [
        'pps_post_views_count',
        'pps_last_visit_date'
    ];
    
    $patterns = [
        'pps_daily_views_%',
        'pps_monthly_views_%',
        'pps_yearly_views_%'
    ];
    
    foreach ($meta_keys as $key) {
        $wpdb->delete($wpdb->postmeta, ['meta_key' => $key]);
    }
    
    foreach ($patterns as $pattern) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE %s",
            $pattern
        ));
    }
    
    // Limpiar cookies de visitas
    $posts = get_posts([
        'post_type' => ['post', 'page'],
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    foreach ($posts as $post_id) {
        $cookie_name = "pps_visited_" . COOKIEHASH . "_$post_id";
        setcookie($cookie_name, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
    }
    
    return true;
}

// Handler para el reset
add_action('admin_post_pps_reset_stats', 'pps_handle_reset_stats');
function pps_handle_reset_stats() {
    if (!current_user_can('manage_options') || !check_admin_referer('pps_reset_stats_nonce')) {
        wp_die(__('No tienes permisos para esta acción.'));
    }
    
    if (pps_reset_all_stats()) {
        wp_redirect(add_query_arg('pps_stats_reset', 'success', admin_url('admin.php?page=pps-view-stats')));
    } else {
        wp_redirect(add_query_arg('pps_stats_reset', 'error', admin_url('admin.php?page=pps-view-stats')));
    }
    exit;
}

// Exportar estadísticas
add_action('admin_post_pps_export_stats', 'pps_export_stats');
function pps_export_stats() {
    if (!current_user_can('manage_options') || !check_admin_referer('pps_export_stats')) {
        wp_die(__('No tienes permisos suficientes.'));
    }

    $args = [
        'post_type' => ['post', 'page'],
        'posts_per_page' => -1,
        'meta_key' => 'pps_post_views_count',
        'orderby' => 'meta_value_num',
        'order' => 'DESC',
        'post_status' => 'publish'
    ];

    $posts = get_posts($args);
    $filename = 'estadisticas-visitas-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Tipo', 'Título', 'Vistas Totales', 'Vistas Hoy', 'Vistas Mes', 'Vistas Año', 'Fecha', 'Última Visita']);
    
    foreach ($posts as $post) {
        $post_id = $post->ID;
        $type = ($post->post_type === 'post') ? 'Artículo' : 'Página';
        
        fputcsv($output, [
            $type,
            $post->post_title,
            get_post_meta($post_id, 'pps_post_views_count', true) ?: 0,
            get_post_meta($post_id, "pps_daily_views_" . date('Y-m-d'), true) ?: 0,
            get_post_meta($post_id, "pps_monthly_views_" . date('Y-m'), true) ?: 0,
            get_post_meta($post_id, "pps_yearly_views_" . date('Y'), true) ?: 0,
            get_the_date('Y-m-d', $post_id),
            get_post_meta($post_id, 'pps_last_visit_date', true) ?: '-'
        ]);
    }
    
    fclose($output);
    exit;
}

// ==================== INTERFAZ ADMIN ====================

function pps_render_view_stats_page() {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes.'));
    }

    // Configuración de paginación
    $per_page = 10;
    $post_page = isset($_GET['post_page']) ? max(1, intval($_GET['post_page'])) : 1;
    $page_page = isset($_GET['page_page']) ? max(1, intval($_GET['page_page'])) : 1;

    // Consultas con paginación
    $args_posts = [
        'post_type' => 'post',
        'posts_per_page' => $per_page,
        'paged' => $post_page,
        'meta_key' => 'pps_post_views_count',
        'orderby' => 'meta_value_num',
        'order' => 'DESC',
        'post_status' => 'publish'
    ];

    $args_pages = [
        'post_type' => 'page',
        'posts_per_page' => $per_page,
        'paged' => $page_page,
        'meta_key' => 'pps_post_views_count',
        'orderby' => 'meta_value_num',
        'order' => 'DESC',
        'post_status' => 'publish'
    ];

    $most_viewed_posts = new WP_Query($args_posts);
    $most_viewed_pages = new WP_Query($args_pages);

    // Obtener estadísticas totales
    $total_post_views = $wpdb->get_var(
        "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta 
         WHERE meta_key = 'pps_post_views_count' 
         AND post_id IN (SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish')"
    ) ?: 0;
    
    $total_page_views = $wpdb->get_var(
        "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta 
         WHERE meta_key = 'pps_post_views_count' 
         AND post_id IN (SELECT ID FROM $wpdb->posts WHERE post_type = 'page' AND post_status = 'publish')"
    ) ?: 0;
    
    $today_views = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta 
             WHERE meta_key = %s", "pps_daily_views_" . date('Y-m-d')
        )
    ) ?: 0;
    
    $month_views = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta 
             WHERE meta_key = %s", "pps_monthly_views_" . date('Y-m')
        )
    ) ?: 0;
    
    $year_views = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta 
             WHERE meta_key = %s", "pps_yearly_views_" . date('Y')
        )
    ) ?: 0;

    // Mostrar notificaciones
    if (isset($_GET['pps_stats_reset'])) {
        if ($_GET['pps_stats_reset'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>Estadísticas reseteadas correctamente.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Error al resetear las estadísticas.</p></div>';
        }
    }

    // Inicio del HTML
    echo '<div class="pps-view-stats-container">';
    echo '<h1>Estadísticas de Visitas</h1>';
    
    // Botones de acción
    echo '<div class="pps-action-buttons">';
    echo '<a href="' . admin_url('admin-post.php?action=pps_export_stats&_wpnonce=' . wp_create_nonce('pps_export_stats')) . '" class="pps-button pps-button-primary">Exportar Estadísticas</a>';
    
    echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="display: inline-block; margin-left: 10px;">';
    echo '<input type="hidden" name="action" value="pps_reset_stats">';
    wp_nonce_field('pps_reset_stats_nonce');
    echo '<button type="submit" class="pps-button pps-button-reset" onclick="return confirm(\'¿Estás seguro que quieres resetear TODAS las estadísticas? Esta acción no se puede deshacer.\')">';
    echo 'Resetear Estadísticas';
    echo '</button>';
    echo '</form>';
    echo '</div>';
    
    echo '<hr>';

    // Resumen estadístico
    echo '<div class="pps-stats-summary">';
    echo '<div class="pps-stat-box">';
    echo '<h3>Visitas Totales</h3>';
    echo '<div class="pps-stat-number">' . number_format($total_post_views + $total_page_views) . '</div>';
    echo '</div>';
    echo '<div class="pps-stat-box">';
    echo '<h3>Visitas Hoy</h3>';
    echo '<div class="pps-stat-number">' . number_format($today_views) . '</div>';
    echo '</div>';
    echo '<div class="pps-stat-box">';
    echo '<h3>Visitas Este Mes</h3>';
    echo '<div class="pps-stat-number">' . number_format($month_views) . '</div>';
    echo '</div>';
    echo '<div class="pps-stat-box">';
    echo '<h3>Visitas Este Año</h3>';
    echo '<div class="pps-stat-number">' . number_format($year_views) . '</div>';
    echo '</div>';
    echo '</div>';

    // Tabla de artículos
    echo '<div class="pps-table-container">';
    echo '<h2>Artículos Más Vistos</h2>';
    echo '<div class="pps-table-controls">';
    echo '<input type="text" id="pps-post-search" placeholder="Buscar artículo..." class="pps-search-input">';
    echo '</div>';
    
    echo '<table class="pps-stats-table" id="pps-post-table">';
    echo '<thead><tr>';
    echo '<th data-sort="string">Título</th>';
    echo '<th data-sort="number">Vistas Totales</th>';
    echo '<th data-sort="number">Vistas Hoy</th>';
    echo '<th data-sort="number">Vistas Mes</th>';
    echo '<th data-sort="number">Vistas Año</th>';
    echo '<th data-sort="date">Fecha</th>';
    echo '<th data-sort="date">Última Visita</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if ($most_viewed_posts->have_posts()) {
        while ($most_viewed_posts->have_posts()) {
            $most_viewed_posts->the_post();
            $post_id = get_the_ID();
            
            $total_views = get_post_meta($post_id, 'pps_post_views_count', true) ?: 0;
            $daily_views = get_post_meta($post_id, "pps_daily_views_" . date('Y-m-d'), true) ?: 0;
            $monthly_views = get_post_meta($post_id, "pps_monthly_views_" . date('Y-m'), true) ?: 0;
            $yearly_views = get_post_meta($post_id, "pps_yearly_views_" . date('Y'), true) ?: 0;
            $date_created = get_the_date('Y-m-d', $post_id);
            $last_visit = get_post_meta($post_id, 'pps_last_visit_date', true) ?: '-';

            echo '<tr>';
            echo '<td>' . esc_html(get_the_title()) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($total_views) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($daily_views) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($monthly_views) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($yearly_views) . '</td>';
            echo '<td>' . esc_html($date_created) . '</td>';
            echo '<td>' . esc_html($last_visit) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7">No hay artículos disponibles.</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';
    
    // Paginación para artículos
    echo '<div class="pps-tablenav">';
    echo '<div class="pps-tablenav-pages">';
    echo paginate_links([
        'base' => add_query_arg('post_page', '%#%'),
        'format' => '',
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
        'total' => $most_viewed_posts->max_num_pages,
        'current' => $post_page,
    ]);
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Tabla de páginas
    echo '<div class="pps-table-container">';
    echo '<h2>Páginas Más Vistas</h2>';
    echo '<div class="pps-table-controls">';
    echo '<input type="text" id="pps-page-search" placeholder="Buscar página..." class="pps-search-input">';
    echo '</div>';
    
    echo '<table class="pps-stats-table" id="pps-page-table">';
    echo '<thead><tr>';
    echo '<th data-sort="string">Título</th>';
    echo '<th data-sort="number">Vistas Totales</th>';
    echo '<th data-sort="number">Vistas Hoy</th>';
    echo '<th data-sort="number">Vistas Mes</th>';
    echo '<th data-sort="number">Vistas Año</th>';
    echo '<th data-sort="date">Fecha</th>';
    echo '<th data-sort="date">Última Visita</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if ($most_viewed_pages->have_posts()) {
        while ($most_viewed_pages->have_posts()) {
            $most_viewed_pages->the_post();
            $post_id = get_the_ID();
            
            $total_views = get_post_meta($post_id, 'pps_post_views_count', true) ?: 0;
            $daily_views = get_post_meta($post_id, "pps_daily_views_" . date('Y-m-d'), true) ?: 0;
            $monthly_views = get_post_meta($post_id, "pps_monthly_views_" . date('Y-m'), true) ?: 0;
            $yearly_views = get_post_meta($post_id, "pps_yearly_views_" . date('Y'), true) ?: 0;
            $date_created = get_the_date('Y-m-d', $post_id);
            $last_visit = get_post_meta($post_id, 'pps_last_visit_date', true) ?: '-';

            echo '<tr>';
            echo '<td>' . esc_html(get_the_title()) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($total_views) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($daily_views) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($monthly_views) . '</td>';
            echo '<td class="pps-number-cell">' . number_format($yearly_views) . '</td>';
            echo '<td>' . esc_html($date_created) . '</td>';
            echo '<td>' . esc_html($last_visit) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7">No hay páginas disponibles.</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';
    
    // Paginación para páginas
    echo '<div class="pps-tablenav">';
    echo '<div class="pps-tablenav-pages">';
    echo paginate_links([
        'base' => add_query_arg('page_page', '%#%'),
        'format' => '',
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
        'total' => $most_viewed_pages->max_num_pages,
        'current' => $page_page,
    ]);
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Gráficos
    echo '<div class="pps-charts-container">';
    echo '<div class="pps-chart-box">';
    echo '<h3>Distribución de Visitas</h3>';
    echo '<div class="pps-chart-wrapper"><canvas id="pps-content-type-chart"></canvas></div>';
    echo '</div>';
    echo '<div class="pps-chart-box">';
    echo '<h3>Visitas por Periodo</h3>';
    echo '<div class="pps-chart-wrapper"><canvas id="pps-time-period-chart"></canvas></div>';
    echo '</div>';
    echo '</div>';
    
    // Pasar datos a JavaScript
    echo '<script>
    window.ppsViewStatsData = {
        postViews: ' . $total_post_views . ',
        pageViews: ' . $total_page_views . ',
        todayViews: ' . $today_views . ',
        monthViews: ' . $month_views . ',
        yearViews: ' . $year_views . '
    };
    </script>';

    echo '</div>'; // Cerrar contenedor principal

    wp_reset_postdata();
}

// ==================== CARGA DE ASSETS ====================

add_action('admin_enqueue_scripts', 'pps_load_admin_assets');
function pps_load_admin_assets($hook) {
    if ($hook !== 'toplevel_page_pps-view-stats') return;
    
    // CSS
    wp_enqueue_style(
        'pps-view-stats-css', 
        plugins_url('assets/css/pps-view-stats.css', __FILE__),
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'assets/css/pps-view-stats.css')
    );
    
    // Chart.js
    wp_enqueue_script(
        'pps-chart-js', 
        'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', 
        array(), 
        '3.7.1', 
        true
    );
    
    // Script principal
    wp_enqueue_script(
        'pps-view-stats-js', 
        plugins_url('assets/js/pps-view-stats.js', __FILE__), 
        array('jquery', 'pps-chart-js'), 
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/pps-view-stats.js'), 
        true
    );
}