<?php
/*
Plugin Name: Daily Recommend
Description: カレンダー連動型 今日のおすすめ記事設定プラグイン
Version: 1.0.0
Author: あなたの名前
*/

if (!defined('ABSPATH')) exit;

// 1. 管理画面にメニューを追加
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

// 2. 管理画面のHTMLルート
function dr_render_admin_page()
{
  echo '<h1>今日のおすすめ</h1><div id="daily-recommend"></div>';
}


/* ==========================================================
 * 2. React / Vite 開発環境専用設定 (管理画面用)
 * ========================================================== */

/**
 * 管理画面のヘッドに React Fast Refresh 用のスクリプトを注入
 */
add_action('admin_head', function () {
  // このプラグインの管理画面以外では注入しない
  $screen = get_current_screen();
  if ($screen->id !== 'toplevel_page_daily-recommend') {
    return;
  }

  // ローカル開発サーバー(Vite)のクライアントコードとリフレッシュランタイムを注入
  echo '
    <script type="module" crossorigin src="https://localhost:5173/@vite/client"></script>
    <script type="module" crossorigin>
      import RefreshRuntime from "https://localhost:5173/@react-refresh"
      RefreshRuntime.injectIntoGlobalHook(window)
      window.$RefreshReg$ = () => {}
      window.$RefreshSig$ = () => (type) => type
      window.__vite_plugin_react_preamble_installed__ = true
    </script>';
}, 1);

/**
 * 管理画面への Reactアプリスクリプト読み込み
 */
add_action('admin_enqueue_scripts', function ($hook) {
  if ($hook !== 'toplevel_page_daily-recommend') {
    return;
  }

  // 💡 確実にローカル開発サーバー（Vite）を見に行かせるため、強制的に true にするか、
  // あるいは $_SERVER['HTTP_HOST'] を見て判定します。開発中は一旦 true で固定するのが最も確実です。
  $is_development = true;

  if ($is_development) {
    // 【開発環境】ViteのHTTPSローカルサーバーから main.tsx を直接読み込む
    $base_url = 'https://localhost:5173';
    wp_enqueue_script('dr-admin-app-dev', "$base_url/src/main.tsx", array(), null, true);

    // PHPのデータをJavaScriptのグローバル変数（window.drData）として渡す
    wp_localize_script('dr-admin-app-dev', 'drData', array(
      'root'  => esc_url_raw(rest_url()),
      'nonce' => wp_create_nonce('wp_rest')
    ));

    // スクリプトタグを type="module" に強制変換する
    add_filter('script_loader_tag', function ($tag, $handle) {
      if ($handle === 'dr-admin-app-dev') {
        return str_replace('src=', 'type="module" src=', $tag);
      }
      return $tag;
    }, 10, 2);
  } else {
    // 【本番環境】ビルドして admin-dist/ に書き出された成果物を読み込む
    // （※将来的に `yarn recommend:build` を叩くとこのディレクトリとファイルが自動生成されます）
    $js_url  = plugin_dir_url(__FILE__) . 'admin-dist/daily-recommend-admin.js';
    $css_url = plugin_dir_url(__FILE__) . 'admin-dist/daily-recommend-admin.css';

    wp_enqueue_script('dr-admin-app-prod', $js_url, array(), '1.0.0', true);
    wp_enqueue_style('dr-admin-style-prod', $css_url, array(), '1.0.0');

    add_filter('script_loader_tag', function ($tag, $handle) {
      if ($handle === 'dr-admin-app-prod') {
        return str_replace('src=', 'type="module" src=', $tag);
      }
      return $tag;
    }, 10, 2);
  }
}, 20);

/**
 * 開発環境のプロトコル不一致対策 (admin_redirectやバッファ置換が必要な場合)
 * ※管理画面全体に影響が出ないよう、プラグインページでのみバッファ置換を走らせます
 */
add_action('admin_init', function () {
  if (isset($_GET['page']) && $_GET['page'] === 'daily-recommend') {
    ob_start(function ($buffer) {
      return str_replace('http://localhost', 'https://localhost', $buffer);
    });
  }
});

/* ==========================================================
 * 3. カスタム REST API エンドポイントの構築
 * ========================================================== */

// REST APIの初期化アクションにフック
add_action('rest_api_init', 'dr_register_rest_routes');

function dr_register_rest_routes()
{
  // 1. 記事一覧取得用のエンドポイント（既存）
  register_rest_route('daily-recommend/v1', '/posts', array(
    'methods'             => 'GET',
    'callback'            => 'dr_get_posts_callback',
    'permission_callback' => 'dr_api_permission_check',
  ));

  // 💡 2. 特定の日付のおすすめ記事を取得するエンドポイント（新規追加）
  register_rest_route('daily-recommend/v1', '/get-recommend', array(
    'methods'             => 'GET',
    'callback'            => 'dr_get_recommend_callback',
    'permission_callback' => 'dr_api_permission_check',
  ));

  // 💡 3. おすすめ記事を保存するエンドポイント（新規追加）
  register_rest_route('daily-recommend/v1', '/save-recommend', array(
    'methods'             => 'POST',
    'callback'            => 'dr_save_recommend_callback',
    'permission_callback' => 'dr_api_permission_check',
  ));
}

/**
 * 簡易的な権限チェック（管理画面を操作できるユーザーのみ許可）
 */
function dr_api_permission_check()
{
  return current_user_can('manage_options');
}

/**
 * 記事一覧取得 API のコールバック関数
 */
function dr_get_posts_callback($request)
{
  // React側から送られてくるパラメータを取得
  $search   = $request->get_param('search');
  $category = $request->get_param('category');
  $page     = $request->get_param('page') ? intval($request->get_param('page')) : 1;
  $per_page = 12; // 1ページあたりの件数（React側の設計と合わせる）

  // WP_Query のクエリ条件をビルド
  $args = array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => $per_page,
    'paged'          => $page,
    'orderby'        => 'date',
    'order'          => 'DESC',
  );

  // 検索キーワードがある場合
  if (! empty($search)) {
    $args['s'] = $search;
  }

  // カテゴリー絞り込みがある場合（スラッグまたはIDで指定可能。ここではIDまたはスラッグとして処理）
  if (! empty($category)) {
    $args['category_name'] = $category; // カテゴリースラグを指定することを想定
  }

  $query = new WP_Query($args);
  $posts = array();

  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $post_id = get_the_ID();

      // サムネイル画像の取得（なければ null）
      $thumbnail_url = get_the_post_thumbnail_url($post_id, 'medium');

      // 記事のファーストカテゴリーを取得
      $categories = get_the_category($post_id);
      $cat_name = ! empty($categories) ? $categories[0]->name : '未分類';

      $posts[] = array(
        'id'        => $post_id,
        'title'     => get_the_title(),
        'thumbnail' => $thumbnail_url ? $thumbnail_url : null,
        'category'  => $cat_name,
      );
    }
  }
  wp_reset_postdata();

  // 次のページが存在するかどうかの判定
  $has_more = $page < $query->max_num_pages;

  // Reactが受け取りやすい形でレスポンスを返す
  return new WP_REST_Response(array(
    'posts'    => $posts,
    'has_more' => $has_more,
  ), 200);
}

/**
 * 【新規】日付指定でおすすめ記事データを取得するコールバック
 */
function dr_get_recommend_callback($request)
{
  $date = $request->get_param('date'); // YYYY-MM-DD
  if (empty($date)) {
    return new WP_Error('invalid_date', '日付が指定されていません', array('status' => 400));
  }

  // データベース（wp_options）からその日付の保存データを取得
  $option_key = 'dr_data_' . $date;
  $post_ids = get_option($option_key, array()); // 保存されていなければ空配列

  $posts = array();
  if (! empty($post_ids) && is_array($post_ids)) {
    foreach ($post_ids as $post_id) {
      $post = get_post($post_id);
      if ($post && $post->post_status === 'publish') {
        $categories = get_the_category($post_id);
        $posts[] = array(
          'id'        => $post_id,
          'title'     => get_the_title($post_id),
          'thumbnail' => get_the_post_thumbnail_url($post_id, 'medium') ?: null,
          'category'  => ! empty($categories) ? $categories[0]->name : '未分類',
        );
      }
    }
  }

  return new WP_REST_Response(array('posts' => $posts), 200);
}

/**
 * 【新規】おすすめ記事データを保存するコールバック
 */
function dr_save_recommend_callback($request)
{
  $params   = $request->get_json_params();
  $date     = isset($params['date']) ? sanitize_text_field($params['date']) : '';
  $post_ids = isset($params['post_ids']) ? array_map('intval', $params['post_ids']) : array();

  if (empty($date)) {
    return new WP_Error('invalid_date', '日付が不正です', array('status' => 400));
  }

  $option_key = 'dr_data_' . $date;

  if (empty($post_ids)) {
    // 配列が空ならデータ削除（枠がすべて空にされたケース）
    delete_option($option_key);
  } else {
    // 最大6件に制限して保存
    $post_ids = array_slice($post_ids, 0, 6);
    update_option($option_key, $post_ids);
  }

  return new WP_REST_Response(array('success' => true, 'message' => '設定を保存しました。'), 200);
}
