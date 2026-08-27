/**
 * `3661` -> `1:01:01`, `61` -> `1:01`, unknown -> `--:--`.
 * @param seconds
 */
export function formatTime( seconds: number ): string {
	if ( ! Number.isFinite( seconds ) || seconds <= 0 ) {
		return '0:00';
	}

	const total = Math.round( seconds );
	const hours = Math.floor( total / 3600 );
	const minutes = Math.floor( ( total % 3600 ) / 60 );
	const rest = total % 60;

	const pad = ( value: number ): string => String( value ).padStart( 2, '0' );

	return hours > 0
		? `${ hours }:${ pad( minutes ) }:${ pad( rest ) }`
		: `${ minutes }:${ pad( rest ) }`;
}

export function clamp( value: number, min: number, max: number ): number {
	return Math.min( max, Math.max( min, value ) );
}

/**
 * Collapse repeated calls into one per animation frame.
 * @param callback
 */
export function rafThrottle< T extends ( ...args: never[] ) => void >(
	callback: T
): ( ...args: Parameters< T > ) => void {
	let frame: number | null = null;
	let queued: Parameters< T > | null = null;

	return ( ...args: Parameters< T > ) => {
		queued = args;

		if ( frame !== null ) {
			return;
		}

		frame = window.requestAnimationFrame( () => {
			frame = null;

			if ( queued ) {
				callback( ...queued );
			}
		} );
	};
}

export function prefersReducedMotion(): boolean {
	return (
		window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ??
		false
	);
}
