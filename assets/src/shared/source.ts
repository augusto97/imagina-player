/**
 * What kind of thing an address points at, decided in the browser.
 *
 * The server has the authoritative version of this in `Media\Provider`; this is
 * the editor's copy, and it exists so an author finds out what they pasted
 * before they save rather than after they publish. The two must agree, so the
 * shapes they accept are kept deliberately identical and a test renders the
 * same list of addresses through both.
 *
 * Strict on purpose. A host is compared against exact names rather than
 * searched for, because `youtube.com.example.net` contains "youtube.com" and
 * is not YouTube.
 */

export type SourceKind = 'youtube' | 'vimeo' | 'file' | 'hls' | 'unknown';

export interface Source {
	kind: SourceKind;
	/** The provider's identifier, when there is one. */
	id: string;
	/** Vimeo's unlisted hash. */
	hash: string;
}

const HOSTS: Record< string, readonly string[] > = {
	youtube: [
		'youtube.com',
		'www.youtube.com',
		'm.youtube.com',
		'music.youtube.com',
		'youtu.be',
		'www.youtu.be',
		'youtube-nocookie.com',
		'www.youtube-nocookie.com',
	],
	vimeo: [ 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ],
};

/** Extensions a browser will play from a `<video>` element. */
const VIDEO = [ 'mp4', 'm4v', 'webm', 'ogv', 'ogg', 'mov' ];

const AUDIO = [ 'mp3', 'm4a', 'aac', 'oga', 'wav', 'flac', 'opus', 'weba' ];

export function identify( raw: string ): Source {
	const nothing: Source = { kind: 'unknown', id: '', hash: '' };
	const value = raw.trim();

	if ( ! value ) {
		return nothing;
	}

	let url: URL;

	try {
		url = new URL( value );
	} catch {
		// A path rather than an address — a media library file, usually.
		return extension( value )
			? { ...nothing, kind: fileKind( value ) }
			: nothing;
	}

	if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
		return nothing;
	}

	const host = url.hostname.toLowerCase();

	if ( HOSTS.youtube.includes( host ) ) {
		const id = youtubeId( host, url );

		return id ? { kind: 'youtube', id, hash: '' } : nothing;
	}

	if ( HOSTS.vimeo.includes( host ) ) {
		return vimeo( url );
	}

	const ext = extension( url.pathname );

	return ext ? { ...nothing, kind: fileKind( url.pathname ) } : nothing;
}

function extension( path: string ): string {
	const name = path.split( '/' ).pop() ?? '';
	const dot = name.lastIndexOf( '.' );

	return dot > 0 ? name.slice( dot + 1 ).toLowerCase() : '';
}

function fileKind( path: string ): SourceKind {
	const ext = extension( path );

	if ( 'm3u8' === ext ) {
		return 'hls';
	}

	return VIDEO.includes( ext ) || AUDIO.includes( ext ) ? 'file' : 'unknown';
}

function youtubeId( host: string, url: URL ): string {
	const segments = url.pathname.split( '/' ).filter( Boolean );
	const valid = ( id: string ): string =>
		/^[A-Za-z0-9_-]{11}$/.test( id ) ? id : '';

	if ( 'youtu.be' === host || 'www.youtu.be' === host ) {
		return valid( segments[ 0 ] ?? '' );
	}

	const v = url.searchParams.get( 'v' );

	if ( v ) {
		return valid( v );
	}

	if (
		segments[ 1 ] &&
		[ 'embed', 'shorts', 'live', 'v' ].includes( segments[ 0 ] )
	) {
		return valid( segments[ 1 ] );
	}

	return '';
}

function vimeo( url: URL ): Source {
	const nothing: Source = { kind: 'unknown', id: '', hash: '' };
	const segments = url.pathname.split( '/' ).filter( Boolean );

	if ( 'video' === segments[ 0 ] && segments[ 1 ] ) {
		segments.shift();
	}

	const id = segments[ 0 ] ?? '';

	if ( ! /^[0-9]{6,12}$/.test( id ) ) {
		return nothing;
	}

	const hash = segments[ 1 ] ?? '';

	return {
		kind: 'vimeo',
		id,
		hash: /^[A-Za-z0-9]{6,20}$/.test( hash ) ? hash : '',
	};
}

/**
 * Does this address give a video rather than audio?
 * @param raw
 */
export function isVideoSource( raw: string ): boolean {
	const source = identify( raw );

	if (
		'youtube' === source.kind ||
		'vimeo' === source.kind ||
		'hls' === source.kind
	) {
		return true;
	}

	return VIDEO.includes( extension( raw.split( '?' )[ 0 ] ) );
}
