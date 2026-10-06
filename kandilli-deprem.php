<?php
/**
 * Plugin Name: Kandilli Deprem Botu
 * Description: Kandilli Rasathanesi RSS (http://koeri.boun.edu.tr/rss/) verilerini çeker ve seçilen kategoride yazı olarak yayınlar.
 * Version: 1.0.0
 * Text Domain: kandilli-deprem
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KANDILLI_RSS_URL', 'http://koeri.boun.edu.tr/rss/' );
define( 'KANDILLI_MAX_ITEMS', 100 );
define( 'KANDILLI_OPTION_CATEGORY', 'kandilli_category' );
define( 'KANDILLI_HASH_META', '_kandilli_hash' );
define( 'KANDILLI_CRON_HOOK', 'kandilli_fetch_event' );

// Her 1 dakikada çalışacak zamanlama.
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['kandilli_every_minute'] = array(
		'interval' => 60,
		'display'  => 'Her 1 dakika',
	);
	return $schedules;
} );

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( KANDILLI_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'kandilli_every_minute', KANDILLI_CRON_HOOK );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( KANDILLI_CRON_HOOK );
} );

add_action( KANDILLI_CRON_HOOK, 'kandilli_fetch_and_publish' );

/**
 * RSS verilerini çeker; en fazla 100 kayıt işler, daha önce eklenenleri atlar.
 */
function kandilli_fetch_and_publish() {
	$category = (int) get_option( KANDILLI_OPTION_CATEGORY );
	if ( $category <= 0 || ! term_exists( $category, 'category' ) ) {
		return 0;
	}

	$response = wp_remote_get( KANDILLI_RSS_URL, array( 'timeout' => 20 ) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return 0;
	}

	$prev = libxml_use_internal_errors( true );
	$xml  = simplexml_load_string( wp_remote_retrieve_body( $response ), 'SimpleXMLElement', LIBXML_NONET );
	libxml_use_internal_errors( $prev );
	if ( false === $xml || ! isset( $xml->channel->item ) ) {
		return 0;
	}

	$count = 0;
	foreach ( $xml->channel->item as $item ) {
		if ( $count >= KANDILLI_MAX_ITEMS ) {
			break;
		}
		$title = trim( (string) $item->title );
		$desc  = trim( (string) $item->description );
		$date  = trim( (string) $item->pubDate );
		$guid  = trim( (string) $item->guid );
		if ( '' === $title ) {
			continue;
		}
		$count++;

		$hash = md5( '' !== $guid ? $guid : $title . '|' . $date . '|' . $desc );
		if ( kandilli_already_imported( $hash ) ) {
			continue;
		}

		$post_id = wp_insert_post( array(
			'post_title'    => sanitize_text_field( $title ),
			'post_content'  => wp_kses_post( $desc ),
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_category' => array( $category ),
		) );
		if ( $post_id && ! is_wp_error( $post_id ) ) {
			add_post_meta( $post_id, KANDILLI_HASH_META, $hash, true );
		}
	}
	return $count;
}

function kandilli_already_imported( $hash ) {
	$found = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'meta_key'       => KANDILLI_HASH_META,
		'meta_value'     => $hash,
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	) );
	return ! empty( $found );
}

// Kandilli Ayarları sayfası.
add_action( 'admin_menu', function () {
	add_options_page( 'Kandilli Ayarları', 'Kandilli Ayarları', 'manage_options', 'kandilli-settings', 'kandilli_settings_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'kandilli_settings', KANDILLI_OPTION_CATEGORY, array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
} );

function kandilli_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Kandilli Ayarları</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'kandilli_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="kandilli_category">Deprem verilerinin yayınlanacağı kategori</label></th>
					<td>
						<?php
						wp_dropdown_categories( array(
							'name'             => KANDILLI_OPTION_CATEGORY,
							'id'               => 'kandilli_category',
							'selected'         => (int) get_option( KANDILLI_OPTION_CATEGORY ),
							'hide_empty'       => 0,
							'show_option_none' => '— Kategori seçin —',
							'option_none_value' => 0,
						) );
						?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
