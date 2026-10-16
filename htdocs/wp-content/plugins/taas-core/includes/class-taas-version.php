<?php
/**
 * Version scheme and platform baseline for the TAAS plugins
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define the TAAS version scheme
 */
class TAAS_Version {

	/**
	 * Define contract version
	 */
	public const CONTRACT = 0;

	/**
	 * Define platform majors that make up segments 2 through 5
	 */
	public const WP_MAJOR = 7;
	public const PHP_MAJOR = 8;
	public const MYSQL_MAJOR = 8;
	public const APACHE_MAJOR = 2;

	/**
	 * Define full platform baseline, including minors
	 *
	 * @var array<string, string>
	 */
	public const BASELINE = array(
		'wordpress' => '7.1',
		'php'       => '8.2',
		'mysql'     => '8.0',
		'apache'    => '2.4',
		'sapi'      => 'cgi-fcgi',
	);

	/**
	 * Return the tag portion of the version, the five fixed segments
	 *
	 * @return string e.g. '0.7.8.8.2'
	 */
	public static function tag(): string {
		return implode(
			'.',
			array(
				self::CONTRACT,
				self::WP_MAJOR,
				self::PHP_MAJOR,
				self::MYSQL_MAJOR,
				self::APACHE_MAJOR,
			)
		);
	}

	/**
	 * Return the full version including the build segment
	 *
	 * @return string e.g. '0.7.8.8.2.417'
	 */
	public static function full(): string {
		return defined( 'TAAS_CORE_VERSION' ) ? TAAS_CORE_VERSION : self::tag() . '.0';
	}

	/**
	 * Read the actual platform versions at runtime
	 *
	 * @return array<string, string>
	 */
	public static function live(): array {
		global $wpdb;

		$apache = '';
		if ( ! empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
			if ( preg_match( '#Apache/([0-9]+\.[0-9]+)#', $software, $matches ) ) {
				$apache = $matches[1];
			}
		}

		return array(
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'mysql'     => $wpdb->db_version(),
			'apache'    => $apache,
			'sapi'      => PHP_SAPI,
		);
	}

	/**
	 * Compare the live platform against the baseline
	 *
	 * @return array{major: array<string, string>, minor: array<string, string>}
	 */
	public static function drift(): array {
		$live   = self::live();
		$majors = array(
			'wordpress' => self::WP_MAJOR,
			'php'       => self::PHP_MAJOR,
			'mysql'     => self::MYSQL_MAJOR,
			'apache'    => self::APACHE_MAJOR,
		);

		$major_drift = array();
		$minor_drift = array();

		foreach ( $majors as $component => $expected_major ) {
			if ( empty( $live[ $component ] ) ) {
				continue;
			}

			$live_major = (int) strtok( $live[ $component ], '.' );

			if ( $live_major !== $expected_major ) {
				$major_drift[ $component ] = sprintf(
					'baseline %d.x, running %s',
					$expected_major,
					$live[ $component ]
				);
				continue;
			}

			$baseline_minor = self::BASELINE[ $component ] ?? '';
			if ( '' !== $baseline_minor && version_compare( $live[ $component ], $baseline_minor, '>' ) ) {
				$minor_drift[ $component ] = sprintf(
					'baseline %s, running %s',
					$baseline_minor,
					$live[ $component ]
				);
			}
		}

		if ( ! empty( $live['sapi'] ) && self::BASELINE['sapi'] !== $live['sapi'] ) {
			$minor_drift['sapi'] = sprintf(
				'baseline %s, running %s',
				self::BASELINE['sapi'],
				$live['sapi']
			);
		}

		return array(
			'major' => $major_drift,
			'minor' => $minor_drift,
		);
	}

	/**
	 * Add a TAAS panel to Tools > Site Health > Info
	 *
	 * @param array $info Existing debug information
	 * @return array
	 */
	public static function site_health_panel( array $info ): array {
		$live  = self::live();
		$drift = self::drift();

		$fields = array(
			'taas_version'  => array(
				'label' => __( 'TAAS Core version', 'taas-core' ),
				'value' => self::full(),
			),
			'taas_contract' => array(
				'label' => __( 'Contract version', 'taas-core' ),
				'value' => (string) self::CONTRACT,
			),
			'taas_baseline' => array(
				'label' => __( 'Platform baseline', 'taas-core' ),
				'value' => sprintf(
					'WordPress %s, PHP %s, MySQL %s, Apache %s (%s)',
					self::BASELINE['wordpress'],
					self::BASELINE['php'],
					self::BASELINE['mysql'],
					self::BASELINE['apache'],
					self::BASELINE['sapi']
				),
			),
			'taas_live'     => array(
				'label' => __( 'Platform running', 'taas-core' ),
				'value' => sprintf(
					'WordPress %s, PHP %s, MySQL %s, Apache %s (%s)',
					$live['wordpress'],
					$live['php'],
					$live['mysql'],
					$live['apache'] ? $live['apache'] : 'unknown',
					$live['sapi']
				),
			),
			'taas_drift'    => array(
				'label' => __( 'Baseline drift', 'taas-core' ),
				'value' => self::drift_summary( $drift ),
			),
		);

		$info['taas-core'] = array(
			'label'       => __( 'TAAS', 'taas-core' ),
			'description' => __( 'Version and platform baseline for the TA Assignment System.', 'taas-core' ),
			'fields'      => $fields,
		);

		return $info;
	}

	/**
	 * Render drift as a human-readable string
	 *
	 * @param array $drift Output of drift()
	 * @return string
	 */
	private static function drift_summary( array $drift ): string {
		if ( empty( $drift['major'] ) && empty( $drift['minor'] ) ) {
			return __( 'None. Platform matches baseline.', 'taas-core' );
		}

		$parts = array();

		foreach ( $drift['major'] as $component => $detail ) {
			/* translators: 1: component name, 2: version detail */
			$parts[] = sprintf( __( 'MAJOR %1$s (%2$s)', 'taas-core' ), $component, $detail );
		}

		foreach ( $drift['minor'] as $component => $detail ) {
			/* translators: 1: component name, 2: version detail */
			$parts[] = sprintf( __( 'minor %1$s (%2$s)', 'taas-core' ), $component, $detail );
		}

		return implode( '; ', $parts );
	}

	/**
	 * Show an admin notice when the platform has drifted from the baseline
	 *
	 * @return void
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$drift = self::drift();

		if ( empty( $drift['major'] ) ) {
			return;
		}

		TAAS_Logger::warn( 'Platform baseline drift detected.', $drift['major'] );

		echo '<div class="notice notice-warning"><p><strong>TAAS:</strong> ';
		echo esc_html__(
			'The server platform no longer matches the version baseline. Re-test and bump the baseline segments.',
			'taas-core'
		);
		echo '</p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $drift['major'] as $component => $detail ) {
			echo '<li>' . esc_html( $component . ': ' . $detail ) . '</li>';
		}
		echo '</ul></div>';
	}
}
