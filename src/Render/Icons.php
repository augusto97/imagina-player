<?php
/**
 * Inline SVG icons.
 *
 * Icons are inlined rather than served as a font or sprite so the player has no
 * second network request and inherits `currentColor` for theming.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Render;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Icons {

	/**
	 * @var array<string, string>
	 */
	private const PATHS = array(
		'play'     => '<path d="M8 5.14v13.72L19 12z"/>',
		'pause'    => '<path d="M6 5h4v14H6zM14 5h4v14h-4z"/>',
		'volume'   => '<path d="M3 9v6h4l5 5V4L7 9H3z"/>',
		'muted'    => '<path d="M3 9v6h4l5 5V4L7 9H3z"/><path d="M16.5 8.5l5 5m0-5l-5 5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/>',
		'download' => '<path d="M12 3v10.2l3.6-3.6 1.4 1.4-6 6-6-6 1.4-1.4L10 13.2V3zM4 19h16v2H4z"/>',
		'back'     => '<path d="M12 5V1L7 6l5 5V7a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z"/>',
		'forward'  => '<path d="M12 5V1l5 5-5 5V7a6 6 0 1 0 6 6h2a8 8 0 1 1-8-8z"/>',
		'spinner'  => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-dasharray="40 20"/>',
	);

	public static function get( string $name, string $class = '' ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="imgp__icon%s" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false">%s</svg>',
			'' !== $class ? ' ' . esc_attr( $class ) : '',
			self::PATHS[ $name ]
		);
	}
}
