<?php
/*
 * Plugin Name: Random Posts Optimizer
 * Description: Плагин для быстрой выборки случайных записей с метаданными. Шорткод: <code>[random_posts]</code>
 * Version: 1.0
 * Author: koSteams
 * Author URI: https://t.me/koSteams
 * Plugin URI: https://kosteams.com
*/

class RandomPostsOptimizer {
    private $methods = [
        'offset'   => 'LIMIT/OFFSET с кэшированием',
        'range'    => 'Случайный диапазон ID',
        'precache' => 'Предварительная выборка ID'
    ];

    public function __construct() {
        // Добавляем страницу настроек
        add_action('admin_menu', [$this, 'add_admin_page']);
        // Шорткод для вывода
        add_shortcode('random_posts', [$this, 'shortcode_handler']);
        // AJAX для переключения метода
        add_action('wp_ajax_switch_method', [$this, 'switch_method']);
        // Ссылка на настройки на странице "Плагины"
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
    }

    // Добавляем ссылку на "Настройки" в списке плагинов
    public function plugin_action_links($links) {
        $settings_link = '<a href="options-general.php?page=random-posts-settings">Настройки</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    // Добавляем страницу настроек
    public function add_admin_page() {
        add_options_page(
            'Настройки Random Posts',
            'Random Posts',
            'manage_options',
            'random-posts-settings',
            [$this, 'settings_page']
        );
    }

    // Страница настроек
    public function settings_page() {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['selected_method'])) {
            update_option('random_posts_method', sanitize_text_field($_POST['selected_method']));
            echo '<div class="notice notice-success"><p>Настройки сохранены!</p></div>';
        }

        $current_method = get_option('random_posts_method', 'offset');
        ?>
        <div class="wrap">
            <h1>Настройки выборки случайных записей</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th>Метод выборки</th>
                        <td>
                            <select name="selected_method">
                                <?php foreach ($this->methods as $key => $name): ?>
                                    <option value="<?= $key ?>" <?= selected($current_method, $key) ?>>
                                        <?= $name ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // Обработчик шорткода
    public function shortcode_handler($atts) {
        $method = get_option('random_posts_method', 'offset');
        $posts = [];

        switch ($method) {
            case 'offset':
                $posts = $this->get_posts_offset_method();
                break;
            case 'range':
                $posts = $this->get_posts_range_method();
                break;
            case 'precache':
                $posts = $this->get_posts_precache_method();
                break;
        }

        ob_start();
        ?>
        <div class="random-posts-container">
            <div class="methods-switcher">
                <form class="method-switch-form">
                    <?php foreach ($this->methods as $key => $name): ?>
                        <button type="button"
                                class="method-btn <?= $key === $method ? 'active' : '' ?>"
                                data-method="<?= $key ?>">
                            <?= $name ?>
                        </button>
                    <?php endforeach; ?>
                </form>
            </div>

            <div class="random-posts-list">
                <?php foreach ($posts as $post): ?>
                    <div class="post-item">
                        <h3><?= $post->post_title ?></h3>
                        <div class="meta">
                            <?php foreach ($post->meta as $meta): ?>
                                <p><strong><?= $meta->meta_key ?>:</strong> <?= $meta->meta_value ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.method-btn').click(function() {
                var method = $(this).data('method');
                $.ajax({
                    url: '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'switch_method',
                        method: method
                    },
                    success: function() {
                        location.reload();
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // Переключение методов через AJAX
    public function switch_method() {
        if (isset($_POST['method']) && in_array($_POST['method'], array_keys($this->methods))) {
            update_option('random_posts_method', sanitize_text_field($_POST['method']));
        }
        wp_die();
    }

    // Метод 1: LIMIT/OFFSET
    private function get_posts_offset_method() {
        global $wpdb;

        // Кэшируем количество постов
        $total = get_transient('random_posts_total');
        if (false === $total) {
            $total = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->posts} 
                WHERE post_type = 'post' 
                  AND post_status = 'publish'
            ");
            set_transient('random_posts_total', $total, HOUR_IN_SECONDS);
        }

        $offset = rand(0, max(0, $total - 10));

        $post_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ID 
            FROM {$wpdb->posts} 
            WHERE post_type = 'post' 
              AND post_status = 'publish' 
            ORDER BY ID 
            LIMIT 10 
            OFFSET %d
        ", $offset));

        return $this->get_posts_with_meta($post_ids);
    }

    // Метод 2: Случайный диапазон ID
    private function get_posts_range_method() {
        global $wpdb;

        $max_id = $wpdb->get_var("
            SELECT MAX(ID) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'post' 
              AND post_status = 'publish'
        ");

        $post_ids = [];
        while (count($post_ids) < 10) {
            $random_id = rand(1, $max_id);
            $ids = $wpdb->get_col($wpdb->prepare("
                SELECT ID 
                FROM {$wpdb->posts} 
                WHERE ID >= %d 
                  AND post_type = 'post' 
                  AND post_status = 'publish' 
                LIMIT 10
            ", $random_id));

            $post_ids = array_unique(array_merge($post_ids, $ids));
            if (count($post_ids) >= 10) break;
        }

        return $this->get_posts_with_meta(array_slice($post_ids, 0, 10));
    }

    // Метод 3: Предварительная выборка ID
    private function get_posts_precache_method() {
        $post_ids = get_transient('random_posts_precached_ids');

        if (false === $post_ids) {
            global $wpdb;
            $post_ids = $wpdb->get_col("
                SELECT ID 
                FROM {$wpdb->posts} 
                WHERE post_type = 'post' 
                  AND post_status = 'publish'
            ");
            shuffle($post_ids);
            set_transient('random_posts_precached_ids', $post_ids, 15 * MINUTE_IN_SECONDS);
        }

        return $this->get_posts_with_meta(array_slice($post_ids, 0, 10));
    }

    // Общая функция для получения метаданных
    private function get_posts_with_meta($post_ids) {
        if (empty($post_ids)) return [];

        global $wpdb;
        $ids_placeholder = implode(',', array_fill(0, count($post_ids), '%d'));

        // Получаем посты
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT * 
            FROM {$wpdb->posts} 
            WHERE ID IN ($ids_placeholder)
        ", $post_ids));

        // Получаем метаданные
        $meta = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, meta_key, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN ($ids_placeholder)
        ", $post_ids));

        // Группируем метаданные с постами
        foreach ($posts as $post) {
            $post->meta = array_filter($meta, function ($m) use ($post) {
                return $m->post_id == $post->ID;
            });
        }

        return $posts;
    }
}

new RandomPostsOptimizer();
