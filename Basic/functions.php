<?php

/* Register Widgets Area */
//function basic_register_widgets(){
if (function_exists('register_sidebar')) {
  /* Register Right Sidbar Blog Widgets */
   register_sidebar( array(
   'name' => 'Right Content Sidebar',
   'id' => 'right-sidebar-1',
   'description' => 'Appears in the right sidebar content area',
   'before_widget' => '<aside id="%1$s" class="widget %2$s">',
   'after_widget' => '</aside>',
   'before_title' => '<h3 class="widget-title">',
   'after_title' => '</h3>',
   ) );

 /* Register Right Sidbar Blog Widgets */
  register_sidebar( array(
  'name' => 'Right Blog Sidebar',
  'id' => 'right-blog-sidebar-1',
  'description' => 'Appears in the right sidebar blog area',
  'before_widget' => '<aside id="%1$s" class="widget %2$s">',
  'after_widget' => '</aside>',
  'before_title' => '<h3 class="widget-title">',
  'after_title' => '</h3>',
  ) );

  /* Register footer Widgets 1 - 3 */
  register_sidebar( array(
  'name' => 'Footer Sidebar 1',
  'id' => 'footer-sidebar-1',
  'description' => 'Appears in the footer area',
  'before_widget' => '<aside id="%1$s" class="widget %2$s">',
  'after_widget' => '</aside>',
  'before_title' => '<h3 class="widget-title">',
  'after_title' => '</h3>',
  ) );

  register_sidebar( array(
  'name' => 'Footer Sidebar 2',
  'id' => 'footer-sidebar-2',
  'description' => 'Appears in the footer area',
  'before_widget' => '<aside id="%1$s" class="widget %2$s">',
  'after_widget' => '</aside>',
  'before_title' => '<h3 class="widget-title">',
  'after_title' => '</h3>',
  ) );

  register_sidebar( array(
  'name' => 'Footer Sidebar 3',
  'id' => 'footer-sidebar-3',
  'description' => 'Appears in the footer area',
  'before_widget' => '<aside id="%1$s" class="widget %2$s">',
  'after_widget' => '</aside>',
  'before_title' => '<h3 class="widget-title">',
  'after_title' => '</h3>',
  ) );

}

add_action('widget_init', 'basic_register_widgets' );

// Creating and activating memu sections
function register_my_menu() {
    register_nav_menu('primary-menu',__( 'Main Menu' ));
}

add_action( 'init', 'register_my_menu' );

function theme_prefix_setup() {
    add_theme_support( 'custom-logo', array(
        'height'      => 100,
        'flex-width' => true,
    ) );
}
add_action( 'after_setup_theme', 'theme_prefix_setup' );

// Create and activate Custom  Logos
function twentysixteen_the_custom_logo()
{
    if (function_exists('the_custom_logo')) {
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        $logo = wp_get_attachment_image_src( $custom_logo_id , 'full' );
        if ( has_custom_logo() ) {
            echo '<img src="'. esc_url( $logo[0] ) .'">';
        } else {
            echo esc_attr( get_bloginfo( 'name' ) );
        }
    }
}

// Enabling Support for Featured Image
if ( function_exists( 'add_theme_support' ) ) {
    add_theme_support('post-thumbnails');
    set_post_thumbnail_size( 1000, 300, array( 'center', 'center')  ); // 50 pixels wide by 50 pixels tall, crop
}