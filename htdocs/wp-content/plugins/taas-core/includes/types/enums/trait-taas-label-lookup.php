<?php
/**
 * Label lookup for enums the API names exactly.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Find an enum case by the label() the API uses for it.
 */
trait TAAS_Label_Lookup {

	/**
	 * Match an API name to a case.
	 *
	 * @param string $text Name from a request.
	 * @return self|null The matching case, or null if nothing matches.
	 */
	public static function try_from_label( string $text ): ?self {
		foreach ( self::cases() as $case ) {
			if ( $case->label() === $text ) {
				return $case;
			}
		}

		return null;
	}
}
