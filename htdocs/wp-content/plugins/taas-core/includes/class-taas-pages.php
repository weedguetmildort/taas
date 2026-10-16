<?php
/**
 * Declare page creation for the TAAS plugins
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and tracks the pages the application depends on
 */
class TAAS_Pages {

	/**
	 * Option key prefix for storing resolved page IDs
	 */
	private const OPTION_PREFIX = 'taas_page_id_';

	/**
	 * Directory holding each page's block markup, relative to the plugin root
	 */
	private const CONTENT_DIR = 'pages/';

	/**
	 * Declare the pages, keyed by slug
	 *
	 * @return array<string, array{title: string, parent: string|null}>
	 */
	private static function definitions(): array {
		return array(
			'admin-page'               => array(
				'title'  => 'Admin Page(need to figure out parsing csv with php)',
				'parent' => null,
			),
			'hr-page'                  => array(
				'title'  => 'HR Page',
				'parent' => null,
			),
			'student-application'      => array(
				'title'  => 'Student Application',
				'parent' => null,
			),
			'suggested-ta-assignments' => array(
				'title'  => 'Suggested TA Assignments',
				'parent' => null,
			),
		);
	}

	/**
	 * Load a page's block markup from pages/<slug>.html
	 *
	 * @param string $slug Page slug
	 * @return string Raw block markup
	 */
	private static function content( string $slug ): string {
		$path = TAAS_CORE_PATH . self::CONTENT_DIR . $slug . '.html';

		if ( ! file_exists( $path ) ) {
			TAAS_Logger::warn(
				'Page content file missing.',
				array(
					'slug' => $slug,
					'path' => $path,
				)
			);
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, not remote.

		return false === $contents ? '' : $contents;
	}

	/**
	 * Create any missing pages
	 *
	 * @return void
	 */
	public static function install(): void {
		$definitions = self::definitions();
		$resolved    = array();

		foreach ( $definitions as $slug => $page ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );

			if ( $existing instanceof WP_Post ) {
				// Restore trashed pages rather than leaving the site with an invisible page
				if ( 'trash' === $existing->post_status ) {
					wp_untrash_post( $existing->ID );
					wp_update_post(
						array(
							'ID'          => $existing->ID,
							'post_status' => 'publish',
						)
					);
					TAAS_Logger::info( 'Restored trashed page.', array( 'slug' => $slug ) );
				}

				$resolved[ $slug ] = $existing->ID;
				self::remember( $slug, $existing->ID );
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_name'    => $slug,
					'post_content' => self::content( $slug ),
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => 1,
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				TAAS_Logger::error(
					'Page creation failed.',
					array(
						'slug'  => $slug,
						'error' => $id->get_error_message(),
					)
				);
				continue;
			}

			$resolved[ $slug ] = $id;
			self::remember( $slug, $id );
			TAAS_Logger::info(
				'Created page.',
				array(
					'slug' => $slug,
					'id'   => $id,
				)
			);
		}

		// Assign parents now that every ID is known
		foreach ( $definitions as $slug => $page ) {
			if ( empty( $page['parent'] ) ) {
				continue;
			}

			if ( ! isset( $resolved[ $slug ], $resolved[ $page['parent'] ] ) ) {
				TAAS_Logger::warn( 'Could not resolve page parent.', array( 'slug' => $slug ) );
				continue;
			}

			wp_update_post(
				array(
					'ID'          => $resolved[ $slug ],
					'post_parent' => $resolved[ $page['parent'] ],
				)
			);
		}
	}

	/**
	 * Overwrite existing pages with the content from pages/<slug>.html
	 *
	 * @return int Number of pages updated
	 */
	public static function sync_content(): int {
		$updated = 0;

		foreach ( array_keys( self::definitions() ) as $slug ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );

			if ( ! $existing instanceof WP_Post ) {
				continue;
			}

			$content = self::content( $slug );

			if ( '' === $content || $content === $existing->post_content ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_content' => $content,
				)
			);

			++$updated;
			TAAS_Logger::info( 'Synced page content from file.', array( 'slug' => $slug ) );
		}

		return $updated;
	}

	/**
	 * Store a page ID so other code can link to it without hardcoding
	 *
	 * @param string $slug Page slug
	 * @param int    $id   Post ID.
	 * @return void
	 */
	private static function remember( string $slug, int $id ): void {
		update_option( self::OPTION_PREFIX . self::option_key( $slug ), $id, false );
	}

	/**
	 * Normalise a slug into an option key fragment
	 *
	 * @param string $slug Page slug
	 * @return string
	 */
	private static function option_key( string $slug ): string {
		return str_replace( '-', '_', $slug );
	}

	/**
	 * Return a managed page's ID, or 0 if it has not been created
	 *
	 * @param string $slug Page slug
	 * @return int
	 */
	public static function id( string $slug ): int {
		return (int) get_option( self::OPTION_PREFIX . self::option_key( $slug ), 0 );
	}

	/**
	 * Return a managed page's permalink, or '' if it does not exist
	 *
	 * @param string $slug Page slug
	 * @return string
	 */
	public static function url( string $slug ): string {
		$id = self::id( $slug );

		if ( 0 === $id ) {
			return '';
		}

		$permalink = get_permalink( $id );

		return $permalink ? $permalink : '';
	}

	/**
	 * Return slugs declared here that have no matching content file
	 *
	 * @return string[]
	 */
	public static function missing_content_files(): array {
		$missing = array();

		foreach ( array_keys( self::definitions() ) as $slug ) {
			if ( ! file_exists( TAAS_CORE_PATH . self::CONTENT_DIR . $slug . '.html' ) ) {
				$missing[] = $slug;
			}
		}

		return $missing;
	}

	/**
	 * Write each managed page's content back to pages/<slug>.html
	 *
	 * @return string[] Slugs whose files changed
	 */
	public static function export(): array {
		if ( ! TAAS_Auth::is_local_dev() ) {
			TAAS_Logger::warn( 'Page export refused outside local dev.' );
			return array();
		}

		$changed = array();

		foreach ( array_keys( self::definitions() ) as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );

			if ( ! $page instanceof WP_Post ) {
				continue;
			}

			$path    = TAAS_CORE_PATH . self::CONTENT_DIR . $slug . '.html';
			$current = file_exists( $path ) ? file_get_contents( $path ) : '';

			if ( $current === $page->post_content ) {
				continue;
			}

			file_put_contents( $path, $page->post_content );
			$changed[] = $slug;
		}

		return $changed;
	}
}
