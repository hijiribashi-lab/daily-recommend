<?php
/*
Plugin Name: Daily Recommend
Description: カレンダー連動型 今日のおすすめ記事設定プラグイン（LSCacheパージ＆カスタム設定機能付き）
Version: 1.1.0
Author: あなたの名前
*/

if (!defined('ABSPATH')) exit;

/* ==========================================================
 * 1. 管理画面メニュー・画面構築
 * ========================================================== */

add_action('admin_menu', 'dr_add_admin_menu');
function dr_add_admin_menu()
{
  add_menu_page(
    '今日のおすすめ設定',
    '今日のおすすめ',
    'manage_options',
    'daily-recommend',
    'dr_render_admin_page',
    'dashicons-calendar-alt',
    26
  );
}

function dr_render_admin_page()
{
  echo '<h1>今日のおすすめ</h1><div id="daily-recommend"></div>';
}


/* ==========================================================
 * 2. 管理画面スクリプト・アセットの読み込み（環境切り替え対応）
 * ========================================================== */

add_action('admin_enqueue_scripts', 'dr_admin_enqueue_assets', 20);
function dr_admin_enqueue_assets($hook)
{
  if ('toplevel_page_daily-recommend' !== $hook) {
    return;
  }

  // 💡 開発時は true、本番公開時は false に切り替えます
  $is_development = false;

  // サイト内の全カテゴリー一覧を取得
  $categories = get_categories(array('hide_empty' => true));
  $cat_list = array();
  foreach ($categories as $cat) {
    $cat_list[] = array(
      'slug' => $cat->slug,
      'name' => $cat->name,
    );
  }

  // 💡 データベースから「現在の設定値」を取得してReactに渡す
  $target_hour = get_option('dr_setting_target_hour', 5);    // デフォルトAM 5:00
  $max_posts   = get_option('dr_setting_max_posts', 6);      // デフォルト6件

  $localize_data = array(
    'root'        => esc_url_raw(rest_url()),
    'nonce'       => wp_create_nonce('wp_rest'),
    'categories'  => $cat_list,
    'config'      => array(
      'targetHour' => intval($target_hour),
      'maxPosts'   => intval($max_posts),
    )
  );

  if ($is_development) {
    // ---- 🛠️ 開発環境（Vite ローカルサーバー接続モード） ----
    $base_url = 'https://localhost:5173';

    add_action('admin_head', function () use ($base_url) {
      echo '
            <script type="module" crossorigin src="' . $base_url . '/@vite/client"></script>
            <script type="module" crossorigin>
                import RefreshRuntime from "' . $base_url . '/@react-refresh"
                RefreshRuntime.injectIntoGlobalHook(window)
                window.$RefreshReg$ = () => {}
                window.$RefreshSig$ = () => (type) => type
                window.__vite_plugin_react_preamble_installed__ = true
            </script>';
    }, 1);

    wp_enqueue_script('dr-admin-app-dev', "$base_url/src/main.tsx", array(), null, true);
    wp_localize_script('dr-admin-app-dev', 'drData', $localize_data);

    add_filter('script_loader_tag', function ($tag, $handle, $src) {
      if ('dr-admin-app-dev' === $handle) {
        return '<script type="module" src="' . esc_url($src) . '"></script>';
      }
      return $tag;
    }, 10, 3);
  } else {
    // ---- 🚀 本番環境（ビルド成果物読み込みモード） ----
    $js_url  = plugin_dir_url(__FILE__) . 'admin-dist/daily-recommend-admin.js';
    $css_url = plugin_dir_url(__FILE__) . 'admin-dist/style.css';

    wp_enqueue_script('dr-admin-app-prod', $js_url, array(), '1.0.0', true);
    wp_localize_script('dr-admin-app-prod', 'drData', $localize_data);

    if (file_exists(plugin_dir_path(__FILE__) . 'admin-dist/style.css')) {
      wp_enqueue_style('dr-admin-app-style', $css_url, array(), '1.0.0');
    }

    add_filter('script_loader_tag', function ($tag, $handle, $src) {
      if ('dr-admin-app-prod' === $handle) {
        return '<script type="module" src="' . esc_url($src) . '"></script>';
      }
      return $tag;
    }, 10, 3);
  }
}

add_action('admin_init', function () {
  if (isset($_GET['page']) && $_GET['page'] === 'daily-recommend') {
    ob_start(function ($buffer) {
      return str_replace('http://localhost', 'https://localhost', $buffer);
    });
  }
});


/* ==========================================================
 * 3. カスタム REST API エンドポイント
 * ========================================================== */

add_action('rest_api_init', 'dr_register_rest_routes');
function dr_register_rest_routes()
{
  // 1. 記事一覧取得
  register_rest_route('daily-recommend/v1', '/posts', array(
    'methods'             => 'GET',
    'callback'            => 'dr_get_posts_callback',
    'permission_callback' => 'dr_api_permission_check',
  ));

  // 2. 日付指定のおすすめ記事取得
  register_rest_route('daily-recommend/v1', '/get-recommend', array(
    'methods'             => 'GET',
    'callback'            => 'dr_get_recommend_callback',
    'permission_callback' => 'dr_api_permission_check',
  ));

  // 3. おすすめ記事データ保存
  register_rest_route('daily-recommend/v1', '/save-recommend', array(
    'methods'             => 'POST',
    'callback'            => 'dr_save_recommend_callback',
    'permission_callback' => 'dr_api_permission_check',
  ));

  // 💡 4. 【新規】更新時間・表示件数などの共通設定を保存するエンドポイント
  register_rest_route('daily-recommend/v1', '/save-settings', array(
    'methods'             => 'POST',
    'callback'            => 'dr_save_settings_callback',
    'permission_callback' => 'dr_api_permission_check',
  ));
}

function dr_api_permission_check()
{
  return current_user_can('manage_options');
}

function dr_get_posts_callback($request)
{
  $search   = $request->get_param('search');
  $category = $request->get_param('category');
  $page     = $request->get_param('page') ? intval($request->get_param('page')) : 1;
  $per_page = 12;

  $args = array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => $per_page,
    'paged'          => $page,
    'orderby'        => 'date',
    'order'          => 'DESC',
  );

  if (!empty($search)) {
    $args['s'] = $search;
  }

  if (!empty($category)) {
    $args['category_name'] = $category;
  }

  $query = new WP_Query($args);
  $posts = array();

  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $post_id = get_the_ID();
      $categories = get_the_category($post_id);
      $cat_name = !empty($categories) ? $categories[0]->name : '未分類';

      $posts[] = array(
        'id'        => $post_id,
        'title'     => get_the_title(),
        'thumbnail' => get_the_post_thumbnail_url($post_id, 'medium') ?: null,
        'category'  => $cat_name,
      );
    }
  }
  wp_reset_postdata();

  return new WP_REST_Response(array(
    'posts'    => $posts,
    'has_more' => $page < $query->max_num_pages,
  ), 200);
}

function dr_get_recommend_callback($request)
{
  $date = $request->get_param('date');
  if (empty($date)) {
    return new WP_Error('invalid_date', '日付が指定されていません', array('status' => 400));
  }

  $option_key = 'dr_data_' . $date;
  $post_ids   = get_option($option_key, array());

  $posts = array();
  if (!empty($post_ids) && is_array($post_ids)) {
    foreach ($post_ids as $post_id) {
      $post = get_post($post_id);
      if ($post && $post->post_status === 'publish') {
        $categories = get_the_category($post_id);
        $posts[] = array(
          'id'        => $post_id,
          'title'     => get_the_title($post_id),
          'thumbnail' => get_the_post_thumbnail_url($post_id, 'medium') ?: null,
          'category'  => !empty($categories) ? $categories[0]->name : '未分類',
        );
      }
    }
  }

  return new WP_REST_Response(array('posts' => $posts), 200);
}

function dr_save_recommend_callback($request)
{
  $params   = $request->get_json_params();
  $date     = isset($params['date']) ? sanitize_text_field($params['date']) : '';
  $post_ids = isset($params['post_ids']) ? array_map('intval', $params['post_ids']) : array();

  if (empty($date)) {
    return new WP_Error('invalid_date', '日付が不正です', array('status' => 400));
  }

  $option_key = 'dr_data_' . $date;
  $max_posts  = intval(get_option('dr_setting_max_posts', 6)); // 💡 動的な上限件数を取得

  if (empty($post_ids)) {
    delete_option($option_key);
  } else {
    $post_ids = array_slice($post_ids, 0, $max_posts);
    update_option($option_key, $post_ids);
  }

  return new WP_REST_Response(array('success' => true, 'message' => '設定を保存しました。'), 200);
}

/**
 * 💡【新規】切り替え時間と表示件数をDBに保存するコールバック
 */
function dr_save_settings_callback($request)
{
  $params      = $request->get_json_params();
  $target_hour = isset($params['target_hour']) ? max(0, min(23, intval($params['target_hour']))) : 5;
  $max_posts   = isset($params['max_posts']) ? max(1, min(12, intval($params['max_posts']))) : 6;

  update_option('dr_setting_target_hour', $target_hour);
  update_option('dr_setting_max_posts', $max_posts);

  // 💡 設定が変更されたら、古いキャッシュが残らないように即座にLSCacheをクリア
  do_action('litespeed_purge', 'H.front');

  return new WP_REST_Response(array('success' => true, 'message' => '共通設定を保存しました。'), 200);
}


/* ==========================================================
 * 4. フロントエンド表示 ＆ LiteSpeed Cache 連動パージロジック
 * ========================================================== */

/**
 * 動的な設定値を元に、判定対象となる日付（YYYY-MM-DD）を算出
 */
function dr_get_target_date_by_custom_hour()
{
  $wp_timestamp = current_time('timestamp');
  $current_hour = intval(date('G', $wp_timestamp));

  // 💡 データベースから設定された時間を取得（なければ5）
  $target_hour  = intval(get_option('dr_setting_target_hour', 5));

  return ($current_hour < $target_hour) ? date('Y-m-d', strtotime('-1 day', $wp_timestamp)) : date('Y-m-d', $wp_timestamp);
}

/**
 * 💡【重要】LiteSpeed Cache連動：日付切り替わり時に自動でトップページのキャッシュを破壊する
 */
add_action('template_redirect', 'dr_litespeed_cache_auto_purge');
function dr_litespeed_cache_auto_purge()
{
  // フロントのトップページへのアクセス時のみ判定を行う
  if (!is_front_page() && !is_home()) {
    return;
  }

  // LiteSpeed Cacheプラグインが有効化されているか確認
  if (!class_exists('LiteSpeed\Purge')) {
    return;
  }

  $target_date = dr_get_target_date_by_custom_hour();
  $last_purged_date = get_option('dr_lscache_last_purged_date', '');

  // 💡 最後にパージした日付と、今表示すべき日付が異なる場合（＝日付変更後、初のアクセス）
  if ($target_date !== $last_purged_date) {
    // トップページのキャッシュのみをピンポイントで強制削除
    do_action('litespeed_purge', 'H.front');
    // フラグを更新し、1日に何度もパージが走るのを防ぐ（サーバー負荷軽減）
    update_option('dr_lscache_last_purged_date', $target_date);
  }
}

// フロント表示用ショートコード [daily_recommend_posts]
add_shortcode('daily_recommend_posts', 'dr_render_recommend_posts_shortcode');
function dr_render_recommend_posts_shortcode()
{
  $target_date = dr_get_target_date_by_custom_hour();
  $option_key  = 'dr_data_' . $target_date;
  $post_ids    = get_option($option_key, array());

  if (empty($post_ids) || !is_array($post_ids)) {
    return '';
  }

  $max_posts = intval(get_option('dr_setting_max_posts', 6)); // 💡 動的な表示件数を取得

  $args = array(
    'post_type'           => 'post',
    'post__in'            => $post_ids,
    'orderby'             => 'post__in',
    'posts_per_page'      => $max_posts, // 💡 設定件数分だけ回す
    'ignore_sticky_posts' => true,
  );

  $the_query = new WP_Query($args);

  if (!$the_query->have_posts()) {
    return '';
  }

  ob_start();
?>
  <div class="stamp-home-new-wrap entry-cards card-2cols tile-card excerpt-view daily-recommend-wrap">
    <?php
    $count = 0;
    while ($the_query->have_posts()) : $the_query->the_post();
      $count++;
      set_query_var('count', $count);
      get_template_part('tmp/entry-card');
    endwhile;

    set_query_var('count', null);
    wp_reset_postdata();
    ?>
  </div>
<?php
  return ob_get_clean();
}
