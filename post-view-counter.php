<?php
/*
Plugin Name: Post and Page View Counter
Description: Estadísticas de visitas con gráficos, filtros, paginación y reseteo.
Version: 1.2
Author: Oriol Llovera
*/

defined('ABSPATH') or die('Acceso directo no permitido');

add_action('template_redirect', 'pps_update_view_count');
function pps_update_view_count() {
    if ((is_single() || is_page()) && !is_user_logged_in() && !is_admin()) {
        $post_id = get_the_ID();
        if (!$post_id) return;

        $cookie_name = "pps_visited_" . COOKIEHASH . "_$post_id";
        if (!isset($_COOKIE[$cookie_name])) {
            $total_views = (int)get_post_meta($post_id, 'pps_post_views_count', true);
            update_post_meta($post_id, 'pps_post_views_count', $total_views + 1);
            update_post_meta($post_id, 'pps_last_visit_date', current_time('timestamp'));

            $today = date('Y-m-d');
            $month = date('Y-m');
            $year = date('Y');
            update_post_meta($post_id, "pps_daily_views_$today", (int)get_post_meta($post_id, "pps_daily_views_$today", true) + 1);
            update_post_meta($post_id, "pps_monthly_views_$month", (int)get_post_meta($post_id, "pps_monthly_views_$month", true) + 1);
            update_post_meta($post_id, "pps_yearly_views_$year", (int)get_post_meta($post_id, "pps_yearly_views_$year", true) + 1);

            pps_track_visit_hour($post_id);
            pps_detect_user_agent($post_id);
            pps_track_country($post_id);

            setcookie($cookie_name, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        }
    }
}

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

register_activation_hook(__FILE__, 'pps_check_geoip_requirement');
function pps_check_geoip_requirement() {
    if (!function_exists('geoip_detect2_get_info_from_ip')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>Para el seguimiento de países, por favor instala el plugin <a href="https://wordpress.org/plugins/geoip-detect/" target="_blank">GeoIP Detection</a> o la extensión PHP GeoIP.</p></div>';
        });
    }
}

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
        'pps_yearly_views_%',
        'pps_hourly_views_%',
        'pps_device_%',
        'pps_browser_%',
        'pps_country_views_%'
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

add_action('admin_post_pps_export_stats', 'pps_export_stats');
function pps_export_stats() {
    if (!current_user_can('manage_options') || !check_admin_referer('pps_export_stats')) {
        wp_die(__('No tienes permisos suficientes.'));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="estadisticas-visitas-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fwrite($output, "\xEF\xBB\xBF");
    
    $args = [
        'post_type' => ['post', 'page'],
        'posts_per_page' => -1,
        'meta_key' => 'pps_post_views_count',
        'orderby' => 'meta_value_num',
        'order' => 'DESC',
        'post_status' => 'publish'
    ];
    
    fputcsv($output, ['Tipo', 'Título', 'Vistas Totales', 'Vistas Hoy', 'Vistas Mes', 'Vistas Año', 'Fecha', 'Última Visita'], ';');
    
    $posts = get_posts($args);
    foreach ($posts as $post) {
        $post_id = $post->ID;
        $type = ($post->post_type === 'post') ? 'Artículo' : 'Página';
        $last_visit = get_post_meta($post_id, 'pps_last_visit_date', true);
        
        if ($last_visit) {
            if (is_numeric($last_visit)) {
                $last_visit = date_i18n('Y-m-d H:i', $last_visit);
            } else {
                $last_visit = date_i18n('Y-m-d H:i', strtotime($last_visit));
            }
        } else {
            $last_visit = '-';
        }
        
        fputcsv($output, [
            $type,
            $post->post_title,
            get_post_meta($post_id, 'pps_post_views_count', true) ?: 0,
            get_post_meta($post_id, "pps_daily_views_" . date('Y-m-d'), true) ?: 0,
            get_post_meta($post_id, "pps_monthly_views_" . date('Y-m'), true) ?: 0,
            get_post_meta($post_id, "pps_yearly_views_" . date('Y'), true) ?: 0,
            get_the_date('Y-m-d', $post_id),
            $last_visit
        ], ';');
    }
    
    global $wpdb;
    
    fputcsv($output, [''], ';');
    fputcsv($output, ['ESTADÍSTICAS AVANZADAS'], ';');
    
    $devices = $wpdb->get_results(
        "SELECT 
            SUBSTRING(meta_key, 12) AS device,
            SUM(CAST(meta_value AS UNSIGNED)) AS total
         FROM $wpdb->postmeta
         WHERE meta_key LIKE 'pps_device_%'
         GROUP BY device"
    );
    
    fputcsv($output, [''], ';');
    fputcsv($output, ['Dispositivos', 'Visitas'], ';');
    foreach ($devices as $device) {
        fputcsv($output, [
            ucfirst($device->device),
            $device->total
        ], ';');
    }
    
    $browsers = $wpdb->get_results(
        "SELECT 
            SUBSTRING(meta_key, 13) AS browser,
            SUM(CAST(meta_value AS UNSIGNED)) AS total
         FROM $wpdb->postmeta
         WHERE meta_key LIKE 'pps_browser_%'
         GROUP BY browser"
    );
    
    fputcsv($output, [''], ';');
    fputcsv($output, ['Navegadores', 'Visitas'], ';');
    foreach ($browsers as $browser) {
        $browser_name = str_replace(['_', '-'], ' ', $browser->browser);
        fputcsv($output, [
            ucfirst($browser_name),
            $browser->total
        ], ';');
    }
    
    $countries = $wpdb->get_results(
        "SELECT 
            SUBSTRING(meta_key, 18) AS country_code,
            SUM(CAST(meta_value AS UNSIGNED)) AS total_views
         FROM $wpdb->postmeta
         WHERE meta_key LIKE 'pps_country_views_%'
         GROUP BY country_code
         ORDER BY total_views DESC"
    );
    
    fputcsv($output, [''], ';');
    fputcsv($output, ['Países', 'Visitas'], ';');
    foreach ($countries as $country) {
        $country_name = $country->country_code;
        if (function_exists('locale_get_display_region')) {
            $country_name = locale_get_display_region('-' . $country->country_code, 'es');
        }
        fputcsv($output, [
            $country_name,
            $country->total_views
        ], ';');
    }
    
    $categories = get_categories(['hide_empty' => true]);
    foreach ($categories as $category) {
        $top_posts = pps_get_top_posts_by_category($category->term_id, 5);
        
        fputcsv($output, [''], ';');
        fputcsv($output, [$category->name], ';');
        fputcsv($output, ['Artículo', 'Visitas'], ';');
        
        if ($top_posts->have_posts()) {
            while ($top_posts->have_posts()) {
                $top_posts->the_post();
                fputcsv($output, [
                    get_the_title(),
                    number_format(get_post_meta(get_the_ID(), 'pps_post_views_count', true) ?: 0)
                ], ';');
            }
        }
        wp_reset_postdata();
    }
    
    fclose($output);
    exit;
}

function pps_track_visit_hour($post_id) {
    $hour = date('H');
    $meta_key = "pps_hourly_views_$hour";
    $current = (int)get_post_meta($post_id, $meta_key, true);
    update_post_meta($post_id, $meta_key, $current + 1);
}

function pps_detect_user_agent($post_id) {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) return;
    
    $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
    
    $device = 'desktop';
    if (strpos($ua, 'mobile') !== false) $device = 'mobile';
    if (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) $device = 'tablet';
    
    $browser = 'other';
    if (strpos($ua, 'chrome') !== false) $browser = 'chrome';
    elseif (strpos($ua, 'firefox') !== false) $browser = 'firefox';
    elseif (strpos($ua, 'safari') !== false) $browser = 'safari';
    elseif (strpos($ua, 'edge') !== false) $browser = 'edge';
    elseif (strpos($ua, 'opera') !== false) $browser = 'opera';
    elseif (strpos($ua, 'msie') !== false || strpos($ua, 'trident') !== false) $browser = 'ie';
    
    update_post_meta($post_id, "pps_device_$device", (int)get_post_meta($post_id, "pps_device_$device", true) + 1);
    update_post_meta($post_id, "pps_browser_$browser", (int)get_post_meta($post_id, "pps_browser_$browser", true) + 1);
}

function pps_track_country($post_id) {
    if (!function_exists('geoip_detect2_get_info_from_ip')) {
        return;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) return;
    
    try {
        $info = geoip_detect2_get_info_from_ip($ip);
        if ($info->country->isoCode) {
            $country_code = sanitize_key($info->country->isoCode);
            $meta_key = "pps_country_views_" . $country_code;
            $current = (int)get_post_meta($post_id, $meta_key, true);
            update_post_meta($post_id, $meta_key, $current + 1);
        }
    } catch (Exception $e) {
        error_log('Error detecting country: ' . $e->getMessage());
    }
}

function pps_get_top_posts_by_category($category_id, $limit = 5) {
    $args = [
        'post_type' => 'post',
        'posts_per_page' => $limit,
        'meta_key' => 'pps_post_views_count',
        'orderby' => 'meta_value_num',
        'order' => 'DESC',
        'post_status' => 'publish',
        'cat' => $category_id
    ];
    
    return new WP_Query($args);
}

function pps_render_hourly_chart() {
    global $wpdb;
    
    $hours = [];
    $views = [];
    
    for ($i = 0; $i < 24; $i++) {
        $hour = str_pad($i, 2, '0', STR_PAD_LEFT);
        $meta_key = "pps_hourly_views_$hour";
        
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta WHERE meta_key = %s",
                $meta_key
            )
        ) ?: 0;
        
        $hours[] = "$hour:00";
        $views[] = $total;
    }
    
    echo '<div class="pps-chart-box">';
    echo '<h3>Horas Pico de Visitas</h3>';
    echo '<div class="pps-chart-wrapper"><canvas id="pps-hourly-chart"></canvas></div>';
    echo '</div>';
    
    echo '<script>
    window.ppsHourlyData = {
        labels: ' . json_encode($hours) . ',
        data: ' . json_encode($views) . '
    };
    </script>';
}

function pps_render_country_stats() {
    global $wpdb;
    
    $results = $wpdb->get_results(
        "SELECT 
            SUBSTRING(meta_key, 18) AS country_code,
            SUM(CAST(meta_value AS UNSIGNED)) AS total_views
         FROM $wpdb->postmeta
         WHERE meta_key LIKE 'pps_country_views_%'
         GROUP BY country_code
         ORDER BY total_views DESC
         LIMIT 10"
    );
    
    if (!$results) {
        echo '<p>No hay datos de países disponibles. Asegúrate de tener instalada la extensión PHP GeoIP o la librería MaxMind.</p>';
        return;
    }
    
    echo '<div class="pps-table-container">';
    echo '<h2>Top 10 Países por Visitas</h2>';
    echo '<table class="pps-stats-table">';
    echo '<thead><tr><th>País</th><th>Visitas</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($results as $row) {
        $clean_code = str_replace('_', '', $row->country_code);
        $country_name = $clean_code;
        
        if (function_exists('locale_get_display_region') && strlen($clean_code) === 2) {
            try {
                $country_name = locale_get_display_region('-' . $clean_code, 'es');
            } catch (Exception $e) {
                $country_name = $clean_code;
            }
        }
        
        echo '<tr>';
        echo '<td>';
        if (strlen($clean_code) === 2 && ctype_alpha($clean_code)) {
            echo '<img src="https://flagcdn.com/16x12/' . strtolower($clean_code) . '.png" 
                  class="pps-country-flag" 
                  alt="' . esc_attr($country_name) . '"> ';
        }
        echo esc_html($country_name);
        echo '</td>';
        echo '<td class="pps-number-cell">' . number_format($row->total_views) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

function pps_render_device_browser_stats() {
    global $wpdb;
    
    $devices = [
        'desktop' => 'Escritorio',
        'mobile' => 'Móvil',
        'tablet' => 'Tablet'
    ];
    
    $device_stats = [];
    foreach ($devices as $key => $name) {
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta WHERE meta_key = %s",
                "pps_device_$key"
            )
        ) ?: 0;
        $device_stats[$name] = $total;
    }
    
    $browsers = [
        'chrome' => 'Chrome',
        'firefox' => 'Firefox',
        'safari' => 'Safari',
        'edge' => 'Edge',
        'opera' => 'Opera',
        'ie' => 'Internet Explorer',
        'other' => 'Otros'
    ];
    
    $browser_stats = [];
    foreach ($browsers as $key => $name) {
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta WHERE meta_key = %s",
                "pps_browser_$key"
            )
        ) ?: 0;
        $browser_stats[$name] = $total;
    }
    
    echo '<div class="pps-charts-container">';
    echo '<div class="pps-chart-box">';
    echo '<h3>Dispositivos</h3>';
    echo '<div class="pps-chart-wrapper"><canvas id="pps-device-chart"></canvas></div>';
    echo '</div>';
    echo '<div class="pps-chart-box">';
    echo '<h3>Navegadores</h3>';
    echo '<div class="pps-chart-wrapper"><canvas id="pps-browser-chart"></canvas></div>';
    echo '</div>';
    echo '</div>';
    
    echo '<script>
    window.ppsDeviceData = {
        labels: ' . json_encode(array_keys($device_stats)) . ',
        data: ' . json_encode(array_values($device_stats)) . '
    };
    window.ppsBrowserData = {
        labels: ' . json_encode(array_keys($browser_stats)) . ',
        data: ' . json_encode(array_values($browser_stats)) . '
    };
    </script>';
}

function pps_render_monthly_comparison() {
    global $wpdb;
    
    $months = [];
    $current_month = date('Y-m');
    
    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $months[] = $month;
    }
    
    $monthly_data = [];
    foreach ($months as $month) {
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta WHERE meta_key = %s",
                "pps_monthly_views_$month"
            )
        ) ?: 0;
        
        $month_name = date('M Y', strtotime($month . '-01'));
        $monthly_data[$month_name] = $total;
    }
    
    echo '<div class="pps-chart-box" style="grid-column: 1 / -1;">';
    echo '<h3>Comparación Mensual de Visitas</h3>';
    echo '<div class="pps-chart-wrapper"><canvas id="pps-monthly-comparison-chart"></canvas></div>';
    echo '</div>';
    
    echo '<script>
    window.ppsMonthlyComparisonData = {
        labels: ' . json_encode(array_keys($monthly_data)) . ',
        data: ' . json_encode(array_values($monthly_data)) . '
    };
    </script>';
}

function pps_render_view_stats_page() {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes.'));
    }

    $per_page = 10;
    $post_page = isset($_GET['post_page']) ? max(1, intval($_GET['post_page'])) : 1;
    $page_page = isset($_GET['page_page']) ? max(1, intval($_GET['page_page'])) : 1;

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

    if (isset($_GET['pps_stats_reset'])) {
        if ($_GET['pps_stats_reset'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>Estadísticas reseteadas correctamente.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Error al resetear las estadísticas.</p></div>';
        }
    }

    echo '<div class="pps-view-stats-container">';
    echo '<h1>Estadísticas de Visitas</h1>';
    
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
            $last_visit = get_post_meta($post_id, 'pps_last_visit_date', true);
            
            if ($last_visit) {
                if (is_numeric($last_visit)) {
                    $last_visit = date_i18n('Y-m-d H:i', $last_visit);
                } else {
                    $last_visit = date_i18n('Y-m-d H:i', strtotime($last_visit));
                }
            } else {
                $last_visit = '-';
            }

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
            $last_visit = get_post_meta($post_id, 'pps_last_visit_date', true);
            
            if ($last_visit) {
                if (is_numeric($last_visit)) {
                    $last_visit = date_i18n('Y-m-d H:i', $last_visit);
                } else {
                    $last_visit = date_i18n('Y-m-d H:i', strtotime($last_visit));
                }
            } else {
                $last_visit = '-';
            }

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

    echo '<div class="pps-table-container pps-categories-accordion">';
    echo '<h2>Artículos más vistos por categoría</h2>';

    $categories = get_categories(['hide_empty' => true]);
    foreach ($categories as $category) {
        echo '<div class="pps-category-group">';
        echo '<h3 class="pps-category-toggle">' . esc_html($category->name) . ' <span class="dashicons dashicons-arrow-down"></span></h3>';
        echo '<div class="pps-category-content" style="display:none;">';
        
        $top_posts = pps_get_top_posts_by_category($category->term_id, 5);
        
        if ($top_posts->have_posts()) {
            echo '<table class="pps-stats-table pps-compact-table">';
            echo '<thead><tr><th>Título</th><th>Vistas</th></tr></thead><tbody>';
            
            while ($top_posts->have_posts()) {
                $top_posts->the_post();
                echo '<tr>';
                echo '<td>' . get_the_title() . '</td>';
                echo '<td class="pps-number-cell">' . number_format(get_post_meta(get_the_ID(), 'pps_post_views_count', true) ?: 0) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>No hay artículos en esta categoría.</p>';
        }
        
        echo '</div></div>';
        wp_reset_postdata();
    }

    echo '</div>';

    pps_render_country_stats();

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

    pps_render_hourly_chart();
    pps_render_device_browser_stats();
    pps_render_monthly_comparison();
    
    echo '<script>
    window.ppsViewStatsData = {
        postViews: ' . $total_post_views . ',
        pageViews: ' . $total_page_views . ',
        todayViews: ' . $today_views . ',
        monthViews: ' . $month_views . ',
        yearViews: ' . $year_views . '
    };
    </script>';

    echo '</div>';

    wp_reset_postdata();
}

add_action('admin_enqueue_scripts', 'pps_load_admin_assets');
function pps_load_admin_assets($hook) {
    if ($hook !== 'toplevel_page_pps-view-stats') return;
    
    wp_enqueue_style(
        'pps-view-stats-css', 
        plugins_url('assets/css/pps-view-stats.css', __FILE__),
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'assets/css/pps-view-stats.css')
    );
    
    wp_enqueue_script(
        'pps-chart-js', 
        'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', 
        array(), 
        '3.7.1', 
        true
    );
    
    wp_enqueue_script(
        'pps-view-stats-js', 
        plugins_url('assets/js/pps-view-stats.js', __FILE__), 
        array('jquery', 'pps-chart-js'), 
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/pps-view-stats.js'), 
        true
    );
}

add_shortcode('pps_views', function($atts) {
    $atts = shortcode_atts([
        'id' => get_the_ID(),
        'format' => true
    ], $atts);
    
    $views = get_post_meta($atts['id'], 'pps_post_views_count', true) ?: 0;
    
    return $atts['format'] ? number_format($views) : $views;
});

add_action('rest_api_init', function() {
    register_rest_route('pps/v1', '/stats/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => function($request) {
            $post_id = $request['id'];
            return [
                'views' => [
                    'total' => get_post_meta($post_id, 'pps_post_views_count', true) ?: 0,
                    'today' => get_post_meta($post_id, 'pps_daily_views_' . date('Y-m-d'), true) ?: 0
                ]
            ];
        },
        'permission_callback' => '__return_true'
    ]);
});