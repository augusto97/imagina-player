<?php
/**
 * Proves — or disproves — that protection is actually protecting anything.
 *
 * Everything else in this directory is the plugin's *intent*. Whether that
 * intent survives contact with the web server is a different question, and it
 * has a well-known way of going wrong: the vault ships an `.htaccess`, nginx
 * has never read one in its life, and the plugin would happily report itself
 * healthy while every file sat in the open behind a directory name.
 *
 * So this asks the server rather than asking the code. It writes a decoy file
 * into the vault, fetches it over real HTTP as an anonymous visitor — no admin
 * cookies, no shortcuts through PHP — and reads the status line. A 200 means
 * the deny rules are not being applied, whatever the settings screen believes.
 *
 * The token checks work the same way: a real request to the real endpoint with
 * a deliberately broken token, so what is under test is the code that will run
 * for a visitor, not a re-implementation of it.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Protection;

use ImaginaPlayer\Settings;
use ImaginaPlayer\Support\Signature;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SelfCheck {

	public const PASS = 'pass';
	public const FAIL = 'fail';
	public const WARN = 'warn';
	public const SKIP = 'skip';

	/**
	 * Seconds to wait on each loopback request. Generous: a cold host can be
	 * slow, and a timeout reported as a failure would be a lie.
	 */
	private const TIMEOUT = 12;

	/**
	 * Run every check and report what the server actually did.
	 *
	 * @return array{status: string, summary: string, checks: array<int, array{id: string, label: string, status: string, detail: string}>}
	 */
	public static function run(): array {
		$checks = array();

		if ( ! ProtectedMedia::is_enabled() ) {
			$checks[] = self::result(
				'enabled',
				__( 'Protection is switched on', 'imagina-player' ),
				self::WARN,
				__( 'Protection is off, so none of the rules below are in force. Nothing here is broken — the files are simply public, as they would be without the plugin.', 'imagina-player' )
			);
		} else {
			$checks[] = self::result(
				'enabled',
				__( 'Protection is switched on', 'imagina-player' ),
				self::PASS,
				''
			);
		}

		$checks[] = self::check_vault_writable();
		$checks[] = self::check_guard_files();

		$decoy = self::check_direct_access();

		$checks[] = $decoy;
		$checks[] = self::check_directory_listing();

		foreach ( self::check_tokens() as $check ) {
			$checks[] = $check;
		}

		$checks[] = self::check_offload();

		return array(
			'status'  => self::worst( $checks ),
			'summary' => self::summarise( $checks ),
			'checks'  => $checks,
		);
	}

	/**
	 * The headline check: fetch a file inside the vault the way anyone could.
	 *
	 * A decoy rather than a client's real recording, so this works on a site
	 * that has not protected anything yet — and so a green result never depends
	 * on there being something to lose.
	 */
	private static function check_direct_access(): array {
		$label = __( 'The server refuses direct access to the vault', 'imagina-player' );

		if ( ! Vault::ensure() ) {
			return self::result( 'direct', $label, self::FAIL, __( 'The protected directory could not be created, so nothing can be stored there.', 'imagina-player' ) );
		}

		$name   = 'imagina-selfcheck-' . wp_generate_password( 12, false ) . '.bin';
		$path   = trailingslashit( Vault::base_dir() ) . $name;
		$secret = 'imagina-player-self-check-' . wp_generate_password( 20, false );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own decoy, removed below.
		if ( false === file_put_contents( $path, $secret ) ) {
			return self::result( 'direct', $label, self::FAIL, __( 'The protected directory is not writable, so protected files cannot be stored there.', 'imagina-player' ) );
		}

		$response = self::fetch( trailingslashit( Vault::base_url() ) . $name );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own decoy.
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort cleanup.

		if ( null === $response ) {
			return self::result(
				'direct',
				$label,
				self::WARN,
				__( 'This site could not make a request to itself, so the check could not run. That is a host restriction on loopback requests, not a sign that protection has failed — but it does mean this cannot be confirmed from here.', 'imagina-player' )
			);
		}

		if ( 200 !== $response['code'] ) {
			return self::result(
				'direct',
				$label,
				self::PASS,
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The server answered %d for a file inside the vault.', 'imagina-player' ), $response['code'] )
			);
		}

		// A 200 that does not contain the decoy is a rewrite or an error page
		// standing in front of the file. Still not served, so still protected.
		if ( ! str_contains( $response['body'], $secret ) ) {
			return self::result(
				'direct',
				$label,
				self::PASS,
				__( 'The server answered 200, but with something other than the file — a rewrite or an error page is standing in front of the vault.', 'imagina-player' )
			);
		}

		return self::result(
			'direct',
			$label,
			self::FAIL,
			Vault::server_honours_htaccess()
				? __( 'A file placed in the vault was downloaded in full over a plain URL. The deny rules are not being applied — check that AllowOverride lets .htaccess take effect for the uploads directory.', 'imagina-player' )
				: __( 'A file placed in the vault was downloaded in full over a plain URL. This server does not read .htaccess, so the deny rule has to be added to its own configuration — the rule is shown below. Until then, protected files are reachable by anyone who knows or guesses the address.', 'imagina-player' )
		);
	}

	/**
	 * A listable vault would hand out every filename in it.
	 */
	private static function check_directory_listing(): array {
		$label    = __( 'The vault cannot be browsed', 'imagina-player' );
		$response = self::fetch( trailingslashit( Vault::base_url() ) );

		if ( null === $response ) {
			return self::result( 'listing', $label, self::SKIP, __( 'Not checked: this site could not make a request to itself.', 'imagina-player' ) );
		}

		if ( 200 !== $response['code'] ) {
			/* translators: %d: HTTP status code. */
			return self::result( 'listing', $label, self::PASS, sprintf( __( 'The server answered %d.', 'imagina-player' ), $response['code'] ) );
		}

		// `index.php` answers 200 with an empty body, which is the desired
		// outcome. An actual listing names the files it found.
		if ( '' === trim( $response['body'] ) ) {
			return self::result( 'listing', $label, self::PASS, __( 'The directory answers with an empty page rather than a listing.', 'imagina-player' ) );
		}

		return self::result(
			'listing',
			$label,
			self::FAIL,
			__( 'The vault directory returned a page listing its contents. Turn directory indexes off for the uploads directory.', 'imagina-player' )
		);
	}

	/**
	 * Real requests to the streaming endpoint with tokens that must be refused.
	 *
	 * These need something protected to point at. Where there is nothing yet
	 * they are reported as not run — never as passing, which is the failure mode
	 * that matters for a check whose whole job is to be trusted.
	 *
	 * @return array<int, array{id: string, label: string, status: string, detail: string}>
	 */
	private static function check_tokens(): array {
		$id = self::a_protected_attachment();

		if ( 0 === $id ) {
			return array(
				self::result(
					'tokens',
					__( 'Signed links are enforced', 'imagina-player' ),
					self::SKIP,
					__( 'Not checked: no file has been protected yet. Protect one under Media and run this again.', 'imagina-player' )
				),
			);
		}

		$base   = home_url( '/' );
		$checks = array();

		$cases = array(
			array(
				'id'     => 'token-missing',
				'label'  => __( 'A link with no token is refused', 'imagina-player' ),
				'token'  => '',
			),
			array(
				'id'     => 'token-tampered',
				'label'  => __( 'A tampered token is refused', 'imagina-player' ),
				'token'  => self::tamper( ProtectedMedia::signed_url( $id ) ),
			),
			array(
				'id'     => 'token-expired',
				'label'  => __( 'An expired token is refused', 'imagina-player' ),
				'token'  => Signature::create( array( 'id' => $id ), 60, ProtectedMedia::CONTEXT, time() - DAY_IN_SECONDS ),
			),
			array(
				'id'     => 'token-other-file',
				'label'  => __( 'A token for one file cannot open another', 'imagina-player' ),
				'token'  => Signature::create( array( 'id' => $id + 1000000 ), 300, ProtectedMedia::CONTEXT ),
			),
		);

		foreach ( $cases as $case ) {
			$url = add_query_arg(
				array(
					ProtectedMedia::QUERY_VAR => $id,
					ProtectedMedia::TOKEN_VAR => $case['token'],
				),
				$base
			);

			$response = self::fetch( $url );

			if ( null === $response ) {
				$checks[] = self::result( $case['id'], $case['label'], self::SKIP, __( 'Not checked: this site could not make a request to itself.', 'imagina-player' ) );
				continue;
			}

			$refused = in_array( $response['code'], array( 401, 403, 404 ), true );

			$checks[] = self::result(
				$case['id'],
				$case['label'],
				$refused ? self::PASS : self::FAIL,
				$refused
					/* translators: %d: HTTP status code. */
					? sprintf( __( 'Refused with %d.', 'imagina-player' ), $response['code'] )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'The server answered %d instead of refusing. The file was served to a request that should not have had it.', 'imagina-player' ), $response['code'] )
			);
		}

		// And the other half of the claim: a *valid* link has to work, or
		// protection has simply broken playback for everyone.
		$response = self::fetch( ProtectedMedia::signed_url( $id ) );
		$label    = __( 'A valid signed link plays', 'imagina-player' );

		if ( null === $response ) {
			$checks[] = self::result( 'token-valid', $label, self::SKIP, __( 'Not checked: this site could not make a request to itself.', 'imagina-player' ) );

			return $checks;
		}

		$protection = Settings::protection();

		if ( ! empty( $protection['require_login'] ) && 401 === $response['code'] ) {
			$checks[] = self::result(
				'token-valid',
				$label,
				self::PASS,
				__( 'Refused with 401, which is correct: “Require login” is on and this check runs as a logged-out visitor.', 'imagina-player' )
			);

			return $checks;
		}

		if ( ! empty( $protection['bind_to_ip'] ) || ! empty( $protection['bind_to_user'] ) ) {
			$checks[] = self::result(
				'token-valid',
				$label,
				self::SKIP,
				__( 'Not checked: links are bound to the visitor, so a link minted here cannot be replayed from here. Play a track on the site instead.', 'imagina-player' )
			);

			return $checks;
		}

		$served = in_array( $response['code'], array( 200, 206 ), true );

		$checks[] = self::result(
			'token-valid',
			$label,
			$served ? self::PASS : self::FAIL,
			$served
				/* translators: %d: HTTP status code. */
				? sprintf( __( 'Served with %d.', 'imagina-player' ), $response['code'] )
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'A correctly signed link was answered with %d. Protected media will not play.', 'imagina-player' ), $response['code'] )
		);

		return $checks;
	}

	private static function check_vault_writable(): array {
		$label = __( 'The protected directory exists and is writable', 'imagina-player' );
		$dir   = Vault::base_dir();

		if ( ! Vault::ensure() ) {
			return self::result( 'vault', $label, self::FAIL, __( 'It could not be created. Check the permissions on wp-content/uploads.', 'imagina-player' ) );
		}

		if ( ! is_writable( $dir ) ) {
			return self::result( 'vault', $label, self::FAIL, __( 'It exists but cannot be written to, so no file can be moved into it.', 'imagina-player' ) );
		}

		return self::result( 'vault', $label, self::PASS, $dir );
	}

	private static function check_guard_files(): array {
		$label   = __( 'The deny rules are in place', 'imagina-player' );
		$dir     = trailingslashit( Vault::base_dir() );
		$missing = array();

		foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $file ) {
			if ( ! file_exists( $dir . $file ) ) {
				$missing[] = $file;
			}
		}

		if ( array() === $missing ) {
			return self::result( 'guards', $label, self::PASS, '.htaccess, web.config, index.php' );
		}

		return self::result(
			'guards',
			$label,
			self::WARN,
			/* translators: %s: comma-separated list of filenames. */
			sprintf( __( 'Missing: %s. They are rewritten when the directory is next created; the check below is what actually decides whether access is denied.', 'imagina-player' ), implode( ', ', $missing ) )
		);
	}

	/**
	 * Not a security question — a capacity one, and the one that decides
	 * whether protection is affordable on a busy site.
	 */
	private static function check_offload(): array {
		$label    = __( 'Streaming is handed to the web server', 'imagina-player' );
		$settings = Settings::protection();
		$mode     = (string) ( $settings['delivery'] ?? 'php' );

		if ( 'php' === $mode || '' === $mode ) {
			return self::result(
				'offload',
				$label,
				self::WARN,
				__( 'Off. Every protected file is streamed through PHP, which holds a worker for the whole length of the track. Fine for a handful of listeners; on a busy site turn on X-Accel-Redirect (nginx) or X-Sendfile (Apache, LiteSpeed).', 'imagina-player' )
			);
		}

		return self::result(
			'offload',
			$label,
			self::PASS,
			'xaccel' === $mode ? 'X-Accel-Redirect' : 'X-Sendfile'
		);
	}

	/**
	 * The most recently protected attachment, if there is one.
	 */
	private static function a_protected_attachment(): int {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Vault::META_PROTECTED, // phpcs:ignore WordPress.DB.SlowMetaQuery.slow_db_query_meta_key -- one row, admin-triggered.
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowMetaQuery.slow_db_query_meta_value -- as above.
			)
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * Flip a character of the token so the signature stops matching.
	 */
	private static function tamper( string $url ): string {
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		parse_str( (string) $query, $args );

		$token = (string) ( $args[ ProtectedMedia::TOKEN_VAR ] ?? '' );

		if ( '' === $token ) {
			return 'not-a-token';
		}

		$last = substr( $token, -1 );

		return substr( $token, 0, -1 ) . ( '0' === $last ? '1' : '0' );
	}

	/**
	 * One anonymous request. No cookies: the point is to be a stranger.
	 *
	 * @return array{code: int, body: string}|null Null when the request could
	 *                                             not be made at all.
	 */
	private static function fetch( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => false,
				'cookies'     => array(),
				'headers'     => array(
					// Ask for the first bytes only. A protected file can be an
					// hour long, and the status line is all this needs.
					'Range' => 'bytes=0-2047',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * @param array<int, array{status: string}> $checks Checks.
	 */
	private static function worst( array $checks ): string {
		foreach ( array( self::FAIL, self::WARN, self::SKIP ) as $level ) {
			foreach ( $checks as $check ) {
				if ( $level === $check['status'] ) {
					return $level;
				}
			}
		}

		return self::PASS;
	}

	/**
	 * @param array<int, array{status: string}> $checks Checks.
	 */
	private static function summarise( array $checks ): string {
		$counts = array_count_values( wp_list_pluck( $checks, 'status' ) );
		$failed = (int) ( $counts[ self::FAIL ] ?? 0 );

		if ( $failed > 0 ) {
			/* translators: %d: number of failed checks. */
			return sprintf( _n( '%d check failed. Protected files are not safe yet.', '%d checks failed. Protected files are not safe yet.', $failed, 'imagina-player' ), $failed );
		}

		$skipped = (int) ( $counts[ self::SKIP ] ?? 0 ) + (int) ( $counts[ self::WARN ] ?? 0 );

		if ( $skipped > 0 ) {
			/* translators: %d: number of checks not confirmed. */
			return sprintf( _n( 'Nothing failed, but %d check could not be confirmed.', 'Nothing failed, but %d checks could not be confirmed.', $skipped, 'imagina-player' ), $skipped );
		}

		return __( 'Every check passed. Files in the vault are reachable only through a valid signed link.', 'imagina-player' );
	}

	/**
	 * @return array{id: string, label: string, status: string, detail: string}
	 */
	private static function result( string $id, string $label, string $status, string $detail ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		);
	}
}
