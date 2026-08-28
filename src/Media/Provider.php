<?php
/**
 * Video that lives somewhere else.
 *
 * A player can point at a file on this site, and until now that was the only
 * thing it could point at. Paste a YouTube address into the video block and
 * WordPress reports no MIME type for it, so the track was not a video, so the
 * block rendered a row of audio controls wrapped around an `<audio>` element
 * whose source was a web page. It showed nothing, played nothing, and said
 * nothing about why.
 *
 * This is the part that recognises those addresses. It is deliberately strict:
 * a host is compared against a list of exact names rather than searched for,
 * because `youtube.com.example.net` contains "youtube.com" and is not YouTube,
 * and an identifier is matched against the shape that provider actually issues
 * rather than taken as whatever was left in the path. What comes out of here
 * ends up inside an iframe URL, so a loose match is a way to put an arbitrary
 * page inside this site's own frame.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Provider {

	/**
	 * Hosts each provider actually answers on.
	 *
	 * Exact names. Anything not on this list is not that provider, whatever it
	 * looks like.
	 *
	 * @var array<string, string[]>
	 */
	private const HOSTS = array(
		'youtube' => array(
			'youtube.com',
			'www.youtube.com',
			'm.youtube.com',
			'music.youtube.com',
			'youtu.be',
			'www.youtu.be',
			'youtube-nocookie.com',
			'www.youtube-nocookie.com',
		),
		'vimeo'   => array(
			'vimeo.com',
			'www.vimeo.com',
			'player.vimeo.com',
		),
	);

	public function __construct(
		/** `youtube`, `vimeo`, or an empty string for a plain file. */
		public readonly string $name = '',
		public readonly string $id = '',
		/** Vimeo's unlisted-video hash, which is part of the address. */
		public readonly string $hash = ''
	) {}

	public function exists(): bool {
		return '' !== $this->name && '' !== $this->id;
	}

	/**
	 * Recognise an address, or return an empty provider.
	 *
	 * @param string $url Any address.
	 */
	public static function detect( string $url ): self {
		$url = trim( $url );

		if ( '' === $url ) {
			return new self();
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new self();
		}

		// A scheme is required. Without one, `wp_parse_url` reads the first
		// path segment as a host and "youtube.com/watch?v=x" typed by hand
		// would resolve, which is a different string from the one that will be
		// fetched later.
		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return new self();
		}

		$host = strtolower( (string) $parts['host'] );
		$name = '';

		foreach ( self::HOSTS as $candidate => $hosts ) {
			if ( in_array( $host, $hosts, true ) ) {
				$name = $candidate;
				break;
			}
		}

		if ( '' === $name ) {
			return new self();
		}

		$path  = (string) ( $parts['path'] ?? '' );
		$query = array();

		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		return 'youtube' === $name
			? self::youtube( $host, $path, $query )
			: self::vimeo( $path );
	}

	/**
	 * @param array<string, mixed> $query Parsed query string.
	 */
	private static function youtube( string $host, string $path, array $query ): self {
		$segments = array_values( array_filter( explode( '/', $path ) ) );

		// youtu.be/ID — the short form puts the identifier where a path would be.
		if ( in_array( $host, array( 'youtu.be', 'www.youtu.be' ), true ) ) {
			return self::youtube_id( (string) ( $segments[0] ?? '' ) );
		}

		// youtube.com/watch?v=ID
		if ( isset( $query['v'] ) && is_string( $query['v'] ) ) {
			return self::youtube_id( $query['v'] );
		}

		// /embed/ID, /shorts/ID, /live/ID, /v/ID
		if ( isset( $segments[1] ) && in_array( $segments[0], array( 'embed', 'shorts', 'live', 'v' ), true ) ) {
			return self::youtube_id( $segments[1] );
		}

		return new self();
	}

	/**
	 * YouTube identifiers are eleven characters of a URL-safe alphabet.
	 *
	 * Anything else is a channel, a playlist, a search, or a mistake — none of
	 * which is a video this can play.
	 */
	private static function youtube_id( string $id ): self {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/', $id )
			? new self( 'youtube', $id )
			: new self();
	}

	private static function vimeo( string $path ): self {
		$segments = array_values( array_filter( explode( '/', $path ) ) );

		// player.vimeo.com/video/ID
		if ( isset( $segments[1] ) && 'video' === $segments[0] ) {
			array_shift( $segments );
		}

		$id = (string) ( $segments[0] ?? '' );

		if ( 1 !== preg_match( '/^[0-9]{6,12}$/', $id ) ) {
			return new self();
		}

		/*
		 * vimeo.com/ID/HASH is an unlisted video. The hash is not a secret in
		 * any useful sense — it is in the address the author pasted — but the
		 * embed will not load without it.
		 */
		$hash = (string) ( $segments[1] ?? '' );

		if ( 1 !== preg_match( '/^[A-Za-z0-9]{6,20}$/', $hash ) ) {
			$hash = '';
		}

		return new self( 'vimeo', $id, $hash );
	}

	/**
	 * The address of the picture the provider shows before anyone presses play.
	 *
	 * YouTube's is predictable, so it costs nothing. Vimeo's is not, and has to
	 * be asked for — see `Providers\VimeoThumbnail`.
	 */
	public function thumbnail_url(): string {
		if ( 'youtube' !== $this->name ) {
			return '';
		}

		/*
		 * `hqdefault` rather than `maxresdefault`: the larger one does not
		 * exist for every video and answers 404 with a grey placeholder image,
		 * which is worse than a smaller picture that is always there.
		 */
		return 'https://i.ytimg.com/vi/' . rawurlencode( $this->id ) . '/hqdefault.jpg';
	}

	/**
	 * The frame source, once someone has asked to watch.
	 *
	 * Not printed until then: an iframe in the page is a request to the
	 * provider on every page view, whether or not anybody presses play.
	 *
	 * @param bool $privacy Use the domain that does not set cookies until playback.
	 */
	public function embed_url( bool $privacy = true ): string {
		if ( 'youtube' === $this->name ) {
			$host = $privacy ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';

			return $host . '/embed/' . rawurlencode( $this->id );
		}

		if ( 'vimeo' === $this->name ) {
			return 'https://player.vimeo.com/video/' . rawurlencode( $this->id );
		}

		return '';
	}

	/** How the provider is named to a person. */
	public function label(): string {
		return array(
			'youtube' => 'YouTube',
			'vimeo'   => 'Vimeo',
		)[ $this->name ] ?? '';
	}
}
