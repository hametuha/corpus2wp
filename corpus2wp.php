<?php
/**
 * Plugin Name:     Corpus2WP
 * Plugin URI:      https://github.com/hametuha/corpus2wp
 * Description:     Add text corpus to WordPress.
 * Author:          Hametuha
 * Author URI:      https://hametuha.co.jp
 * Text Domain:     c2wp
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         c2wp
 */

defined( 'ABSPATH' ) || die();

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require __DIR__ . '/vendor/autoload.php';

WP_CLI::add_command( 'corpus', \Hametuha\Corpus2WP\Aozora::class );
