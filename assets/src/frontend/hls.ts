/**
 * Adaptive streaming, loaded only for the streams that need it.
 *
 * Two reasons this is its own file rather than part of the video chrome. It is
 * by far the heaviest thing the plugin ships — hls.js is around 400 KB, more
 * than twenty times everything else put together — and most videos are a plain
 * MP4 that has no use for it. So it sits behind its own dynamic import, reached
 * only when the source is an `.m3u8`, and only when the browser cannot play one
 * by itself.
 *
 * Safari can. On iOS it is the *only* way to play HLS, since Media Source
 * Extensions are not available in the browser at all. Loading hls.js there
 * would be 400 KB spent to do worse than the built-in.
 *
 * The signing is the part that matters. A protected stream is not one file but
 * a manifest and a few hundred segments, and signing only the manifest protects
 * nothing: the segment URLs are inside it, in plain text, and anyone who fetches
 * it has them. So every segment request is signed too, which is what `xhrSetup`
 * is for.
 */

import type Hls from 'hls.js';
import type { ErrorData, Level, ManifestParsedData } from 'hls.js';

/** Query keys carried from the manifest onto every segment it names. */
type Params = Array< [ string, string ] >;

export interface HlsHost {
	root: HTMLElement;
	media: HTMLVideoElement;
	/** Opens the shared control-bar panel, for the button that asked. */
	menu: (
		items: Array< { label: string; active: boolean; onPick: () => void } >,
		opener: HTMLElement | null
	) => void;
	i18n: Record< string, string >;
	/** The stream was refused: its signed address has run out. */
	expired?: () => void;
}

export function canPlayNatively( media: HTMLVideoElement ): boolean {
	return '' !== media.canPlayType( 'application/vnd.apple.mpegurl' );
}

export function isHlsSource( src: string ): boolean {
	return /\.m3u8(\?|#|$)/i.test( src );
}

/**
 * Everything the manifest URL carries that its segments will also need.
 *
 * A signed stream puts its credential in the query string — that is how Bunny's
 * token authentication works, and how our own signed links work. hls.js resolves
 * segment URLs against the manifest but does *not* inherit its query, so without
 * this every segment arrives unsigned and the whole stream 403s after the first
 * few seconds.
 * @param manifest
 */
function signature( manifest: string ): Params {
	try {
		const url = new URL( manifest, window.location.href );

		return Array.from( url.searchParams.entries() );
	} catch {
		return [];
	}
}

/**
 * Put the manifest's credentials back onto a segment URL.
 *
 * Same origin only. Sending a site's tokens to whatever third-party host a
 * manifest happens to name would be handing the credential away.
 * @param target
 * @param params
 * @param manifest
 */
function sign( target: string, params: Params, manifest: string ): string {
	if ( 0 === params.length ) {
		return target;
	}

	try {
		const url = new URL( target, window.location.href );
		const origin = new URL( manifest, window.location.href ).origin;

		if ( url.origin !== origin ) {
			return target;
		}

		for ( const [ key, value ] of params ) {
			// A segment that already carries the key keeps its own: the manifest
			// may well have named a different one deliberately.
			if ( ! url.searchParams.has( key ) ) {
				url.searchParams.set( key, value );
			}
		}

		return url.toString();
	} catch {
		return target;
	}
}

export class HlsStream {
	private hls: Hls | null = null;

	private readonly host: HlsHost;

	private levels: Level[] = [];

	/** The credentials segments are signed with, and the manifest they came from. */
	private signing: { params: Params; manifest: string } = {
		params: [],
		manifest: '',
	};

	/** Whether the quality button has its listener; it is wired once. */
	private qualityBound = false;

	constructor( host: HlsHost ) {
		this.host = host;
	}

	/**
	 * @param src The manifest URL, credentials and all.
	 * @return Whether hls.js took over. False means the browser is playing the
	 * stream itself, which is the better outcome where it is possible.
	 */
	async attach( src: string ): Promise< boolean > {
		if ( canPlayNatively( this.host.media ) ) {
			return false;
		}

		const { default: HlsCtor } = await import(
			/* webpackChunkName: "imagina-hls" */ 'hls.js'
		);

		if ( ! HlsCtor.isSupported() ) {
			return false;
		}

		this.signing = { params: signature( src ), manifest: src };

		this.hls = new HlsCtor( {
			// The browser's own bandwidth estimate is better than a guess, and
			// capping to the element's size stops a 4K rendition being fetched
			// for a player 400 pixels wide.
			capLevelToPlayerSize: true,
			xhrSetup: ( xhr: XMLHttpRequest, url: string ) => {
				xhr.open(
					'GET',
					sign( url, this.signing.params, this.signing.manifest ),
					true
				);
			},
		} );

		this.hls.on(
			HlsCtor.Events.MANIFEST_PARSED,
			( _event, data: ManifestParsedData ) => {
				this.levels = data.levels;
				this.showQualityButton();
			}
		);

		/*
		 * A network or media error mid-stream is recoverable and common — a
		 * dropped segment on a phone changing cell. Only give up on the third
		 * in a row: a segment arriving resets the count, or three drops spread
		 * over an hour-long lecture ended the stream for good.
		 */
		let recoveries = 0;

		this.hls.on( HlsCtor.Events.FRAG_LOADED, () => {
			recoveries = 0;
		} );

		this.hls.on( HlsCtor.Events.ERROR, ( _event, data: ErrorData ) => {
			if ( ! data.fatal || ! this.hls ) {
				return;
			}

			/*
			 * Refused rather than lost: a signed address that has run out. Not
			 * worth retrying — the same address will be refused again — so the
			 * player is asked for a fresh one, which comes back through
			 * `replaceSource()`.
			 */
			const status = ( data.response as { code?: number } | undefined )
				?.code;

			if (
				HlsCtor.ErrorTypes.NETWORK_ERROR === data.type &&
				( 401 === status || 403 === status || 410 === status ) &&
				this.host.expired
			) {
				this.host.expired();

				return;
			}

			if ( recoveries >= 3 ) {
				this.destroy();
				this.host.root.classList.add( 'has-error' );

				return;
			}

			recoveries++;

			if ( HlsCtor.ErrorTypes.NETWORK_ERROR === data.type ) {
				this.hls.startLoad();
			} else if ( HlsCtor.ErrorTypes.MEDIA_ERROR === data.type ) {
				this.hls.recoverMediaError();
			} else {
				this.destroy();
				this.host.root.classList.add( 'has-error' );
			}
		} );

		// The element's own `src` would make the browser try to fetch a manifest
		// it cannot parse, and log an error for a file hls.js is about to handle.
		this.host.media.removeAttribute( 'src' );
		this.hls.loadSource( src );
		this.hls.attachMedia( this.host.media );

		return true;
	}

	private showQualityButton(): void {
		const button = this.host.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--quality'
		);

		// One rendition is not a choice.
		if ( ! button || this.levels.length < 2 ) {
			return;
		}

		button.hidden = false;

		/*
		 * Once. The manifest is parsed again after a recovery, and each parse
		 * added another listener: the first opened the menu and the second
		 * closed it in the same click.
		 */
		if ( this.qualityBound ) {
			return;
		}

		this.qualityBound = true;

		button.addEventListener( 'click', () => {
			const auto = this.host.i18n.qualityAuto ?? 'Auto';
			const current = this.hls?.currentLevel ?? -1;

			this.host.menu(
				[
					{
						label: auto,
						active: -1 === current,
						onPick: () => {
							if ( this.hls ) {
								this.hls.currentLevel = -1;
							}
						},
					},
					// Highest first: a person opening this menu is usually reaching
					// for the best picture, not the worst.
					...this.levels
						.map( ( level, index ) => ( { level, index } ) )
						.sort( ( a, b ) => b.level.height - a.level.height )
						.map( ( { level, index } ) => ( {
							label: level.height
								? level.height + 'p'
								: Math.round( level.bitrate / 1000 ) + ' kbps',
							active: current === index,
							onPick: () => {
								if ( this.hls ) {
									this.hls.currentLevel = index;
								}
							},
						} ) ),
				],
				button
			);
		} );
	}

	/**
	 * Carry on from the same moment at a fresh signed address.
	 * @param url
	 */
	replaceSource( url: string ): void {
		if ( ! this.hls ) {
			return;
		}

		const at = this.host.media.currentTime;
		const playing = ! this.host.media.paused;
		const params = signature( url );

		// The segments are signed from the manifest's own credentials, so the
		// new ones have to replace the old in what `xhrSetup` reads.
		this.signing = { params, manifest: url };
		this.hls.loadSource( url );
		this.hls.startLoad( at );

		if ( playing ) {
			void this.host.media.play().catch( () => undefined );
		}
	}

	destroy(): void {
		this.hls?.destroy();
		this.hls = null;
	}
}

export { sign as signSegmentUrl, signature as manifestSignature };
