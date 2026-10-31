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
	 * Directory holding each page's HTML content, relative to the plugin root
	 */
	private const CONTENT_DIR = 'pages/';

	/**
	 * Declare the pages, keyed by slug
	 *
	 * Set 'front_page' on the one page WordPress should serve at the site root.
	 * Its slug still exists, but WordPress redirects it to /.
	 *
	 * @return array<string, array{title: string, parent: string|null, front_page?: bool}>
	 */
	private static function definitions(): array {
		return array(
			'front-page'                     => array(
				'title'      => 'Student Home Page',
				'parent'     => null,
				'front_page' => true,
			),
			'student-application'            => array(
				'title'  => 'Student Application',
				'parent' => null,
			),
			'check-application-status'       => array(
				'title'  => 'Check Application Status',
				'parent' => null,
			),
			'professor-homepage'             => array(
				'title'  => 'Professor Home Page',
				'parent' => null,
			),
			'professor-page'                 => array(
				'title'  => 'Professor Page',
				'parent' => null,
			),
			'hr-page'                        => array(
				'title'  => 'HR Page',
				'parent' => null,
			),
			'admin-page'                     => array(
				'title'  => 'Admin Home Page',
				'parent' => null,
			),
			'manage-course-listings-admin'   => array(
				'title'  => 'Admin Manage Course Listings',
				'parent' => null,
			),
			'view-student-application-admin' => array(
				'title'  => 'Admin View Student Application',
				'parent' => null,
			),
		);
	}

	/**
	 * Load a page's HTML content from pages/<slug>.html
	 *
	 * @param string $slug Page slug
	 * @return string Raw HTML content
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

		if ( false === $contents ) { return ''; }
		// Raw student HTML imports use WordPress's standard Custom HTML block.
		if ( in_array( $slug, array( 'front-page', 'student-application', 'check-application-status' ), true ) && ! has_blocks( $contents ) ) {
			return "<!-- wp:html -->\n" . $contents . "\n<!-- /wp:html -->";
		}
		return $contents;
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
					self::save(
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

			$id = self::save(
				array(
					'post_title'   => $page['title'],
					'post_name'    => $slug,
					'post_content' => self::content( $slug ),
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => 1,
				)
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

			self::save(
				array(
					'ID'          => $resolved[ $slug ],
					'post_parent' => $resolved[ $page['parent'] ],
				)
			);
		}

		self::set_front_page( $definitions, $resolved );
	}

	/**
	 * Insert or update a page exactly as given
	 *
	 * Page files are trusted plugin code, so this bypasses the two things
	 * WordPress does to user-submitted content: kses filtering, which strips
	 * <script> and <style> when the current user lacks unfiltered_html (always
	 * true on an anonymous admin request), and unslashing, which would strip
	 * the backslash from JavaScript escapes such as backslash-u0026.
	 *
	 * @param array<string, mixed> $postarr Post fields; an 'ID' means update
	 * @return int|WP_Error Post ID, or the error
	 */
	private static function save( array $postarr ) {
		$filtered = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		if ( $filtered ) {
			kses_remove_filters();
		}

		$postarr = wp_slash( $postarr );
		$result  = isset( $postarr['ID'] ) ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );

		if ( $filtered ) {
			kses_init_filters();
		}

		return $result;
	}

	/**
	 * Serve the page flagged 'front_page' at the site root
	 *
	 * Same as Settings > Reading > "A static page", so the homepage is
	 * version controlled like every other page.
	 *
	 * @param array<string, array> $definitions Page definitions
	 * @param array<string, int>   $resolved Page IDs keyed by slug
	 * @return void
	 */
	private static function set_front_page( array $definitions, array $resolved ): void {
		foreach ( $definitions as $slug => $page ) {
			if ( empty( $page['front_page'] ) || ! isset( $resolved[ $slug ] ) ) {
				continue;
			}

			if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $resolved[ $slug ] ) {
				return;
			}

			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $resolved[ $slug ] );
			TAAS_Logger::info( 'Set front page.', array( 'slug' => $slug ) );
			return;
		}
	}

	/**
	 * Sync repository-owned pages while preserving editable student pages.
	 *
	 * Student source files seed new pages. Saved WordPress content is authoritative
	 * after that; replacing it requires an explicit import.
	 *
	 * @param bool     $force Explicitly import source, including editable student pages.
	 * @param string[] $slugs Optional subset to import.
	 * @return int Number of pages updated
	 */
	public static function sync_content( bool $force = false, array $slugs = array() ): int {
		if ( $force && ! TAAS_Auth::is_local_dev() && ! current_user_can( 'manage_options' ) ) {
			return 0;
		}
		$updated = 0;

		foreach ( array_keys( self::definitions() ) as $slug ) {
			if ( $slugs && ! in_array( $slug, $slugs, true ) ) {
				continue;
			}
			if ( ! $force && in_array( $slug, array( 'front-page', 'student-application', 'check-application-status' ), true ) ) {
				continue;
			}
			$existing = get_page_by_path( $slug, OBJECT, 'page' );

			if ( ! $existing instanceof WP_Post ) {
				continue;
			}

			$content = self::content( $slug );

			if ( '' === $content || $content === $existing->post_content ) {
				continue;
			}

			$result = self::save(
				array(
					'ID'           => $existing->ID,
					'post_content' => $content,
				)
			);

			if ( is_wp_error( $result ) ) {
				TAAS_Logger::error(
					'Page sync failed.',
					array(
						'slug'  => $slug,
						'error' => $result->get_error_message(),
					)
				);
				continue;
			}

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
			$content = $page->post_content;
			if ( in_array( $slug, array( 'front-page', 'student-application', 'check-application-status' ), true ) ) {
				$blocks = array_values( array_filter( parse_blocks( $content ), static fn( $block ) => null !== $block['blockName'] || '' !== trim( $block['innerHTML'] ) ) );
				if ( 1 === count( $blocks ) && 'core/html' === $blocks[0]['blockName'] ) {
					$content = trim( $blocks[0]['innerHTML'] ) . "\n";
				}
			}

			if ( $current === $content ) {
				continue;
			}

			file_put_contents( $path, $content );
			$changed[] = $slug;
		}

		return $changed;
	}
}
