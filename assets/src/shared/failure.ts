/**
 * Turning a failed measurement into a sentence somebody can act on.
 *
 * Its own file, and not because it is large. It lived inside the editor's
 * notice component, where nothing could reach it: the mapping is the part that
 * decides what a person is told, it has been wrong three times, and every time
 * the way it was wrong was a message that matched no case and fell through to
 * the catch-all — which said the browser was not allowed to read the file, when
 * the file had been read perfectly and something else had gone wrong.
 *
 * Here it can be run on its own and asked what it says.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * What went wrong, in words somebody can act on.
 *
 * The failures here have different answers — a file the browser cannot reach
 * is a server setting, a file it cannot decode is a format problem, and a file
 * that runs out of memory is neither — and they were all reported as "some
 * files could not be measured", which tells you nothing at all.
 *
 * @param error Whatever was thrown.
 */
export function reason( error: unknown ): string {
	const raw = error instanceof Error ? error.message : String( error ?? '' );

	/*
	 * A large file is fetched in pieces, and a failure part-way through carries
	 * which piece it was. That matters: a first piece refused is a different
	 * problem from a ninth piece refused, which is a far end that started
	 * saying no once it had been asked a dozen times.
	 */
	const [ message, where ] = raw.split( '|' );

	/*
	 * Appended once, here, rather than in every branch below. It was in some of
	 * them and not others, which is the sort of thing that looks fine until the
	 * branch that matters is one of the others.
	 */
	return explain( message ) + ( where ? ' (' + where + ')' : '' );
}

/**
 * What went wrong, in words somebody can act on.
 *
 * @param message The tag thrown by the measuring code.
 */
function explain( message: string ): string {
	/*
	 * The doorway on this site tried and was refused by the file's own server.
	 * The most common cause by a distance is hotlink protection or a signed-URL
	 * rule on a bucket or CDN, and no setting here can change that — so the
	 * message points at the place that can.
	 */
	if ( message.startsWith( 'proxy-upstream-' ) ) {
		const status = message.replace( 'proxy-upstream-', '' );

		if ( 'unreachable' === status ) {
			return __(
				'this site could not reach the file’s own server',
				'imagina-player'
			);
		}

		return sprintf(
			/* translators: %s: HTTP status the remote server returned. */
			__(
				'the server hosting the file answered %s to this site as well — check that domain’s hotlink protection or signed-link rules',
				'imagina-player'
			),
			status
		);
	}

	if ( 'proxy-not-media' === message ) {
		return __(
			'the address does not return an audio or video file',
			'imagina-player'
		);
	}

	if ( 'proxy-too-large' === message ) {
		return __(
			'the file is larger than this site will fetch on your behalf',
			'imagina-player'
		);
	}

	/*
	 * Nothing to do with the file or its host: this site could not open a
	 * temporary file to download into. Almost always a full disk or an upload
	 * directory that is not writable, and it is worth saying so, because every
	 * other failure here points somewhere else entirely.
	 */
	if ( 'proxy-no-temp-file' === message ) {
		return __(
			'this site could not open a temporary file to download into — check the server’s free disk space and that the uploads folder is writable',
			'imagina-player'
		);
	}

	if ( 'proxy-bad-url' === message ) {
		return __(
			'that address was refused as unsafe to fetch',
			'imagina-player'
		);
	}

	if ( message.startsWith( 'fetch-failed' ) ) {
		const status = message.replace( 'fetch-failed-', '' );

		/*
		 * 401 and 403 from this site's own doorway is the nonce, not the file:
		 * the request reached WordPress and WordPress would not have it.
		 */
		if ( '401' === status || '403' === status ) {
			return __(
				'this site refused the request that fetches the file — reload the editor and try again',
				'imagina-player'
			);
		}

		/*
		 * A gateway error with none of this plugin's own reasons attached did
		 * not come from this plugin: every refusal it makes says which step
		 * gave up. Something between the browser and PHP answered instead — a
		 * firewall, a security plugin, a proxy, or the web server after PHP
		 * stopped.
		 *
		 * Which of those it is cannot be told apart from here, and guessing has
		 * already cost somebody an afternoon and a server setting they were
		 * warned not to change. So this names what is known and points at the
		 * one place that can see the rest.
		 */
		/*
		 * A gateway error carries none of this plugin's reasons for a second
		 * reason as well as the first: a web server may replace a 5xx from PHP
		 * with its own page, header and body alike. Refusals are sent as 4xx
		 * now so they survive that, which means a 5xx here really is somebody
		 * else's.
		 */
		/*
		 * 424 is the status this plugin sends its own refusals as, precisely so
		 * they survive a web server that replaces a 5xx from PHP with its own
		 * page. Arriving here with no reason attached means even that was
		 * stripped — header and body both — so the status is all that is left,
		 * and blaming the file or the browser for it would be guessing.
		 */
		if ( '424' === status ) {
			return __(
				'this site refused to fetch the file and the reason did not survive the trip — Settings → Imagina Player → Waveforms has a check that asks the server directly and reports it',
				'imagina-player'
			);
		}

		if ( '502' === status || '504' === status || '503' === status ) {
			return sprintf(
				/* translators: %s: the HTTP status returned. */
				__(
					'something between the browser and WordPress answered %s — this plugin did not, because every refusal it makes says why. Settings → Imagina Player → Waveforms has a check that asks the server directly and reports what it finds.',
					'imagina-player'
				),
				status
			);
		}

		return sprintf(
			/* translators: %s: HTTP status code, or "?" when there was none. */
			__(
				'the server answered %s when asked for the file',
				'imagina-player'
			),
			status || '?'
		);
	}

	if ( 'length-mismatch' === message ) {
		return __(
			'the download stopped early — the file may be behind something that cuts long transfers off',
			'imagina-player'
		);
	}

	if ( 'no-audio-context' === message ) {
		return __( 'this browser cannot decode audio', 'imagina-player' );
	}

	if ( 'decode-failed' === message ) {
		return __( 'nothing in the file decoded as audio', 'imagina-player' );
	}

	if ( /decode/i.test( message ) ) {
		return __(
			'the browser could not decode it — check that the file plays',
			'imagina-player'
		);
	}

	/*
	 * Not a failure of the file at all — the measuring was stopped, because the
	 * block was removed or the address changed while it ran. Reported so that a
	 * message does appear, rather than a silent nothing that reads as a hang.
	 */
	if ( 'aborted' === message ) {
		return __(
			'measuring was stopped before it finished',
			'imagina-player'
		);
	}

	if ( message.startsWith( 'slice-empty' ) ) {
		return __(
			'the server sent nothing back for part of the file',
			'imagina-player'
		);
	}

	/*
	 * A network error with no status is what a cross-origin refusal looks like
	 * from here: the browser will not say more than "failed", on purpose.
	 *
	 * It is also what everything unrecognised used to look like, which is how a
	 * refused slice — a thing with a perfectly good status on it — was reported
	 * as the browser not being allowed to look.
	 */
	return __(
		'the browser could not read it, which is usually a cross-origin refusal',
		'imagina-player'
	);
}
