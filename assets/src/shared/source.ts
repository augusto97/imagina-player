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

/**
 * Where, if anywhere, the editor should say something about this address.
 *
 * The rule is about what an author is looking at. Inside the block canvas
 * everything reads as the post — that is what the canvas is for — so a line
 * saying "this is a YouTube video" printed there looks like something that will
 * be published, and reporting a fact the author already knows is not worth that
 * confusion. It belongs in the sidebar with the rest of the block's settings.
 *
 * The exception is an address the player cannot play. That is not a remark
 * about the block, it is a fault in it, and a fault has to be where the eye
 * already is.
 */
export type Placement = 'none' | 'sidebar' | 'canvas';

export function placement( raw: string ): Placement {
	if ( ! raw.trim() ) {
		return 'none';
	}

	const kind = identify( raw ).kind;

	if ( 'youtube' === kind || 'vimeo' === kind ) {
		return 'sidebar';
	}

	// A file or a stream needs no remark at all: it is the ordinary case and
	// the preview underneath is about to show it working.
	return 'file' === kind || 'hls' === kind ? 'none' : 'canvas';
}

/**
 * Which of the shared control toggles mean anything for this medium.
 *
 * The block's Controls panel was generated straight from the preset override
 * map, which describes an audio player, so a video block offered "Show
 * thumbnail" — a field the video layout never renders, because a video's still
 * is the poster and has its own control — and the Colours panel offered a
 * waveform colour and a played-portion colour for a picture that has no
 * waveform. Switches that do nothing are worse than missing ones: they are a
 * promise the player does not keep.
 *
 * `sticky` is the worst of them, because it does something rather than nothing:
 * it pins the player to the bottom of the window as a full-width bar, which is
 * a mini audio player and, applied to a video, a whole sixteen-by-nine picture
 * lying across the foot of the screen. A floating video that follows the reader
 * is a real feature and a different one; offering this switch instead is not a
 * cheaper version of it.
 */
const AUDIO_ONLY = [ 'show_thumbnail', 'show_artist', 'sticky' ];

const AUDIO_ONLY_COLOURS = [ 'waveColor', 'waveProgress', 'metaColor' ];

export function controlApplies( key: string, isVideo: boolean ): boolean {
	return ! isVideo || ! AUDIO_ONLY.includes( key );
}

export function colourApplies( attribute: string, isVideo: boolean ): boolean {
	return ! isVideo || ! AUDIO_ONLY_COLOURS.includes( attribute );
}
