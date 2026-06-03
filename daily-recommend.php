<?php

/**
 * Plugin Name: Daily Recommend
 * Description: カレンダーから特定の日付に最大6件のおすすめ記事をマニュアル登録し、AM5時切り替えでトップページに表示するプラグイン（Reactベース）。
 * Version: 1.0.0
 * Author: Piyo
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'dr_add_admin_menu');

function dr_add_admin_menu()
{
  add_menu_page(
    '今日のおすすめ設定',      // ページのタイトルタグ
    '今日のおすすめ',          // 管理画面のメニュー名
    'manage_options',          // 必要な権限（管理者のみ）
    'daily-recommend',         // メニュースラッグ
    'dr_render_admin_page',    // HTMLを出力する関数（ここにReactが割り込む）
    'dashicons-calendar-alt',  // アイコン（カレンダー）
    26                         // 表示位置
  );
}

// 管理画面のHTML出力（この中にReactアプリがマウントされます）
function dr_render_admin_page()
{
  echo '<div id="dr-admin-app"></div>';
}
