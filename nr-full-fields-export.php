<?php

/*
Plugin Name: NR Full Fields Export
Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
Description: Exports all page data (H1, Title Tags, Metadata, site structure, and custom fields) to CSV directly from the admin GUI - no terminal/WP-CLI step required.
Version: 1.0
Author: NativeRank
Author URI: http://URI_Of_The_Plugin_Author
License: A "Slug" license name e.g. GPL2
*/


const NR_FULL_EXPORT_FOLDER = WP_CONTENT_DIR . '/uploads/nr-full-exports/';
define( 'NR_FULL_EXPORT_URL', content_url( '/uploads/nr-full-exports' ) );
require 'lib/plugin-update-checker/plugin-update-checker.php';

add_action( 'plugins_loaded', function () {
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://wp-plugins.nativerank.com/wp-update-server/?action=get_metadata&slug=nr-full-fields-export',
		__FILE__,
		'nr-full-fields-export'
	);
} );

class NR_FULL_EXPORTER {

	public $progress;

	private function getMetaValueFromKey( $meta_key = '', $values = [] ) {
		return ( isset( $values[ $meta_key ] ) && is_array( $values[ $meta_key ] ) ) ? ( isset( $values[ $meta_key ][0] ) ? $values[ $meta_key ][0] : '' ) : '';
	}

	private function startProgress( $label, $count ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return \WP_CLI\Utils\make_progress_bar( $label, $count );
		}

		return null;
	}

	private function processAllPages() {

		$out      = array();
		$defaults = array(
			'depth'        => 0,
			'show_date'    => '',
			'date_format'  => get_option( 'date_format' ),
			'child_of'     => 0,
			'exclude'      => '',
			'title'        => __( 'Pages' ),
			'echo'         => 1,
			'authors'      => '',
			'sort_column'  => 'menu_order, post_title',
			'link_before'  => '',
			'link_after'   => '',
			'item_spacing' => 'preserve',
			'walker'       => '',
		);

		$r                 = wp_parse_args( '', $defaults );
		$r['hierarchical'] = 0;
		$pages             = get_pages( $r );

		$this->progress = $this->startProgress( 'Gathering Pages', count( $pages ) );

		$fieldOption = get_option( 'nativerank_seo_1055__cf_fields', [] );

		$CF_KEYS = array_keys( $fieldOption );

		global $wpdb;

		$result = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT meta_key FROM $wpdb->postmeta WHERE meta_key LIKE 'attr:%'"
		), ARRAY_A );

		if ( ! empty( $result ) ) {
			$result = array_map( function ( $field ) {
				return $field['meta_key'];
			}, $result );
		}


		$titles = array(
			'id',
			'label',
			'h1',
			'title_tag',
			'meta_description',
			'parent_page',
			'slug',
			'content',
			'template',
			'page_type',
			'featured_image',
			'body_images'
		);


		$titles = array_merge( $titles, $result, $CF_KEYS );


		$out[] = $titles;

		foreach ( $pages as $page ) {

			$bodyImages = get_field( 'body_images', $page->ID );

			if ( is_array( $bodyImages ) ) {
				$bodyImages = array_map( function ( $i ) {
					return isset( $i['images'] ) ? $i['images']['ID'] : $i['image']['ID'];
				}, $bodyImages );

				$bodyImages = implode( '|', $bodyImages );
			}


			$keys = get_post_custom( $page->ID );
			$row  = array(
				$page->ID,
				$page->post_title,
				$this->getMetaValueFromKey( 'NR - H1', $keys ),
				$this->getMetaValueFromKey( '_yoast_wpseo_title', $keys ),
				$this->getMetaValueFromKey( '_yoast_wpseo_metadesc', $keys ),
				get_page_uri( $page->post_parent ) ? get_page_uri( $page->post_parent ) : 0,
				$page->post_name,
				wp_slash( $page->post_content ),
				get_page_template_slug( $page->ID ),
				$this->getMetaValueFromKey( 'nr_page_type', $keys ),
				get_post_thumbnail_id( $page->ID ),
				$bodyImages
			);
			if ( ! empty( $result ) ) {
				foreach ( $result as $meta_key ) {
					$row[] = $this->getMetaValueFromKey( $meta_key, $keys );
				}
			}

			if ( ! empty( $fieldOption ) ) {
				foreach ( $fieldOption as $clean_cf_key ) {
					$row[] = $this->getMetaValueFromKey( $clean_cf_key, $keys );
				}
			}

			$out[] = $row;

			if ( $this->progress ) {
				$this->progress->tick();
			}
		}

		if ( $this->progress ) {
			$this->progress->finish();
		}

		$header = array_shift( $out );
		usort( $out, function ( $a, $b ) {
			return $a[0] <=> $b[0];
		} );
		array_unshift( $out, $header );

		return $out;
	}

	public function exportPages() {
		return $this->processAllPages();
	}

	public static function arrayToCsv( $data = [], $csvFile = 'seo-export.csv' ) {

		$progress = null;
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Adding data to CSV', count( $data ) - 1 );
		}

		$uploads_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'nr-full-exports/';
		wp_mkdir_p( $uploads_dir );


		// Open the file for writing
		$fileHandle = fopen( $uploads_dir . $csvFile, "w" );

		if ( $fileHandle === false ) {
			die( "Error: Cannot open the file for writing." );
		}

		fwrite( $fileHandle, chr( 239 ) . chr( 187 ) . chr( 191 ) );

		fputcsv( $fileHandle, $data[0] );


		for ( $i = 1; $i < count( $data ); $i ++ ) {
			$cleanRow = array_map( function ( $field ) {

				$field = mb_convert_encoding( $field, 'UTF-8', 'auto' );
				$field = str_replace( [ "\r", "\n" ], ' ', $field );
				// Escape double quotes (CSV requires "" for a quote inside a quoted string)
				$field = str_replace( '"', '""', $field );

				// Manually wrap fields in double quotes
				return "\"$field\"";

			}, $data[ $i ] );

			fputs( $fileHandle, implode( ",", $cleanRow ) . PHP_EOL );

			if ( $progress ) {
				$progress->tick();
			}
		}


		fclose( $fileHandle );

		if ( $progress ) {
			$progress->finish();
		}

		return [
			'url'  => trailingslashit( wp_upload_dir()['baseurl'] ) . 'nr-full-exports/' . $csvFile,
			'path' => NR_FULL_EXPORT_FOLDER . $csvFile
		];
	}


}


if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'nr-full-export-all', function () {
		WP_CLI::log( 'Starting Export' );
		$path = run_nr_full_export();
		WP_CLI::success( "Exported to {$path['url']}" );
		WP_CLI::success( "Exported to {$path['path']}" );
	} );
}


// Runs the export and writes the CSV. Shared by the WP-CLI command and the admin-post handler below.
function run_nr_full_export() {
	$pages = ( new NR_FULL_EXPORTER() )->exportPages();
	date_default_timezone_set( 'America/Denver' );
	$time = date( 'm_d_Y_h-i-s_a', time() );

	return NR_FULL_EXPORTER::arrayToCsv( $pages, "seo-export-$time.csv" );
}


// Add Admin Menu
add_action( 'admin_menu', function () {
	add_menu_page( 'NR Full Export', 'NR Full Export', 'manage_options', 'nr-full-export', 'nr_full_export_page' );
} );


// Handles the "Run Export" button: runs the export in-process, then redirects back to the admin page.
add_action( 'admin_post_nr_full_run_export', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}
	check_admin_referer( 'nr_full_run_export' );

	@set_time_limit( 0 );
	run_nr_full_export();

	wp_safe_redirect( add_query_arg( 'nr_full_export', 'done', admin_url( 'admin.php?page=nr-full-export' ) ) );
	exit;
} );

// Handles the "Purge Old Exports" button: deletes existing export files, then redirects back.
add_action( 'admin_post_nr_full_purge_export', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}
	check_admin_referer( 'nr_full_purge_export' );

	$fileCount = nr_full_purge_files();

	wp_safe_redirect( add_query_arg( 'nr_full_purged', $fileCount, admin_url( 'admin.php?page=nr-full-export' ) ) );
	exit;
} );


// Function to List Files in Export Folder
function nr_full_list_export_files() {
	$folder = NR_FULL_EXPORT_FOLDER;

	if ( ! is_dir( $folder ) ) {
		mkdir( $folder, 0755, true );
	}

	$files = array_diff( scandir( $folder ), [ '.', '..' ] );

	// Sort by file modification time (latest first)
	usort( $files, function ( $a, $b ) use ( $folder ) {
		return filemtime( $folder . '/' . $b ) - filemtime( $folder . '/' . $a );
	} );

	return $files;
}


function nr_full_human_filesize( $bytes, $decimals = 2 ) {
	$sz     = 'BKMGTP';
	$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

	return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . @$sz[ isset( $factor ) ? $factor : 2 ];
}


function nr_full_purge_files() {
	$filesDeleted = [];
	$files        = glob( NR_FULL_EXPORT_FOLDER . "*" ); // get all file names
	foreach ( $files as $file ) { // iterate files
		if ( is_file( $file ) ) {
			$filesDeleted[] = unlink( $file ); // delete file
		}
	}

	return count( array_filter( $filesDeleted, function ( $res ) {
		return ! empty( $res );
	} ) );
}

function nr_full_export_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_GET['nr_full_export'] ) && $_GET['nr_full_export'] === 'done' ) {
		echo "<div class='updated'><p><strong>Export complete.</strong></p></div>";
	}

	if ( isset( $_GET['nr_full_purged'] ) ) {
		$fileCount = intval( $_GET['nr_full_purged'] );
		echo "<div class='notice notice-warning'><p><strong>$fileCount</strong> Files Deleted</p></div>";
	}

	// List Available Files
	$files = nr_full_list_export_files();

	?>
    <div class="wrap">
        <h1>NR Full Export & CSV Viewer</h1>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<?php wp_nonce_field( 'nr_full_run_export' ); ?>
            <input type="hidden" name="action" value="nr_full_run_export">
            <button type="submit" class="button button-primary">Run Export</button>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<?php wp_nonce_field( 'nr_full_purge_export' ); ?>
            <input type="hidden" name="action" value="nr_full_purge_export">
            <button type="submit" class="button button-delete" onclick="return confirm('Delete all existing export files?');">Purge Old Exports</button>
        </form>

        <h2>Available Files in Exports Folder</h2>
        <ul>
			<?php
			if ( ! empty( $files ) ) {
				foreach ( $files as $file ) {
					$file_url = NR_FULL_EXPORT_URL . '/' . $file;
					echo "<li><a href='" . esc_url( $file_url ) . "' download='" . esc_attr( $file ) . "'>" . esc_html( $file ) . "</a> (" . nr_full_human_filesize( filesize( NR_FULL_EXPORT_FOLDER . $file ) ) . ")</li>";
				}
			} else {
				echo "<li>No files found.</li>";
			}
			?>
        </ul>
    </div>
	<?php
}
