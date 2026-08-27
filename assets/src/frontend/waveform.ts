import { resample } from './peaks';

interface WaveformOptions {
	barWidth: number;
	gap: number;
	reflection: number;
	rounded: boolean;
}

/**
 * Canvas waveform.
 *
 * The bars are painted once per size/peaks change into an offscreen canvas; each
 * frame only composites that bitmap and tints the played portion, so scrubbing a
 * 400-bar waveform costs two `drawImage` calls rather than 400 `fillRect`s.
 */
export class Waveform {
	private readonly canvas: HTMLCanvasElement;

	private readonly context: CanvasRenderingContext2D | null;

	private readonly offscreen: HTMLCanvasElement;

	private readonly offscreenContext: CanvasRenderingContext2D | null;

	private options: WaveformOptions;

	private peaks: Float32Array = new Float32Array( 0 );

	private progress = 0;

	private width = 0;

	private height = 0;

	private dpr = 1;

	private waveColor = '#333333';

	private progressColor = '#c04ec4';

	private lastPaintedProgressPx = -1;

	/** True once we know no waveform is coming and a plain bar is drawn instead. */
	private placeholderOnly = false;

	constructor( canvas: HTMLCanvasElement, options: WaveformOptions ) {
		this.canvas = canvas;
		this.context = canvas.getContext( '2d' );
		this.offscreen = document.createElement( 'canvas' );
		this.offscreenContext = this.offscreen.getContext( '2d' );
		this.options = options;
	}

	setColors( wave: string, progress: string ): void {
		if ( wave === this.waveColor && progress === this.progressColor ) {
			return;
		}

		this.waveColor = wave || this.waveColor;
		this.progressColor = progress || this.progressColor;

		this.paintBars();
		this.composite( true );
	}

	setPeaks( peaks: Float32Array ): void {
		this.peaks = peaks;
		this.placeholderOnly = false;
		this.paintBars();
		this.composite( true );
	}

	/**
	 * Settle for a plain progress bar.
	 *
	 * Called when no waveform could be obtained. A row of stubby equal bars reads
	 * as a player that failed; a clean bar reads as a deliberate design.
	 */
	setPlaceholder(): void {
		this.placeholderOnly = true;
		this.paintBars();
		this.composite( true );
	}

	hasPeaks(): boolean {
		return this.peaks.length > 0;
	}

	setProgress( progress: number ): void {
		this.progress = Number.isFinite( progress ) ? Math.min( 1, Math.max( 0, progress ) ) : 0;
		this.composite();
	}

	/**
	 * Re-measure against the element box. Returns false when the element is not
	 * laid out yet (display:none, or still inside a closed accordion).
	 */
	resize(): boolean {
		const rect = this.canvas.getBoundingClientRect();

		if ( rect.width < 1 || rect.height < 1 ) {
			return false;
		}

		const dpr = window.devicePixelRatio || 1;

		if (
			Math.abs( rect.width - this.width ) < 0.5 &&
			Math.abs( rect.height - this.height ) < 0.5 &&
			dpr === this.dpr
		) {
			return true;
		}

		this.width = rect.width;
		this.height = rect.height;
		this.dpr = dpr;

		for ( const canvas of [ this.canvas, this.offscreen ] ) {
			canvas.width = Math.round( rect.width * dpr );
			canvas.height = Math.round( rect.height * dpr );
		}

		this.canvas.style.width = `${ rect.width }px`;
		this.canvas.style.height = `${ rect.height }px`;

		this.paintBars();
		this.composite( true );

		return true;
	}

	barCount(): number {
		const step = Math.max( 1, this.options.barWidth + this.options.gap );

		return Math.max( 1, Math.floor( this.width / step ) );
	}

	/**
	 * Draw the waveform silhouette in the base colour.
	 */
	private paintBars(): void {
		const ctx = this.offscreenContext;

		if ( ! ctx || this.width < 1 || this.height < 1 ) {
			return;
		}

		ctx.setTransform( this.dpr, 0, 0, this.dpr, 0, 0 );
		ctx.clearRect( 0, 0, this.width, this.height );
		ctx.fillStyle = this.waveColor;

		const { barWidth, gap, reflection, rounded } = this.options;
		const bars = this.barCount();
		const step = barWidth + gap;
		const mainHeight = this.height * ( 1 - reflection );
		const reflectionHeight = this.height * reflection;
		const radius = rounded ? Math.min( barWidth, 6 ) / 2 : 0;

		if ( this.placeholderOnly && this.peaks.length === 0 ) {
			// One continuous bar across the middle, which the progress tint fills
			// exactly like a normal seek bar.
			const trackHeight = Math.max( 4, Math.min( 8, this.height * 0.12 ) );

			this.drawBar(
				ctx,
				0,
				( mainHeight - trackHeight ) / 2,
				this.width,
				trackHeight,
				trackHeight / 2
			);

			return;
		}

		// Before peaks arrive, the same slim bar stands in — never a row of stubs.
		const values = this.peaks.length > 0 ? resample( this.peaks, bars ) : null;

		if ( ! values ) {
			const trackHeight = Math.max( 4, Math.min( 8, this.height * 0.12 ) );

			ctx.globalAlpha = 0.45;
			this.drawBar(
				ctx,
				0,
				( mainHeight - trackHeight ) / 2,
				this.width,
				trackHeight,
				trackHeight / 2
			);
			ctx.globalAlpha = 1;

			return;
		}

		for ( let i = 0; i < bars; i++ ) {
			const value = values[ i ];
			const x = i * step;
			const height = Math.max( 1, value * mainHeight );

			this.drawBar( ctx, x, mainHeight - height, barWidth, height, radius );

			if ( reflectionHeight > 0 ) {
				const mirrored = Math.max( 1, value * reflectionHeight );

				ctx.globalAlpha = 0.35;
				this.drawBar( ctx, x, mainHeight, barWidth, mirrored, radius );
				ctx.globalAlpha = 1;
			}
		}
	}

	private drawBar(
		ctx: CanvasRenderingContext2D,
		x: number,
		y: number,
		width: number,
		height: number,
		radius: number
	): void {
		if ( radius > 0 && typeof ctx.roundRect === 'function' ) {
			ctx.beginPath();
			ctx.roundRect( x, y, width, height, radius );
			ctx.fill();

			return;
		}

		ctx.fillRect( x, y, width, height );
	}

	/**
	 * Blit the silhouette and tint everything left of the playhead.
	 */
	private composite( force = false ): void {
		const ctx = this.context;

		if ( ! ctx || this.width < 1 || this.height < 1 ) {
			return;
		}

		const progressPx = Math.round( this.progress * this.width );

		if ( ! force && progressPx === this.lastPaintedProgressPx ) {
			return;
		}

		this.lastPaintedProgressPx = progressPx;

		ctx.setTransform( this.dpr, 0, 0, this.dpr, 0, 0 );
		ctx.clearRect( 0, 0, this.width, this.height );
		ctx.globalCompositeOperation = 'source-over';
		ctx.drawImage( this.offscreen, 0, 0, this.width, this.height );

		if ( progressPx > 0 ) {
			// `source-atop` paints only where the silhouette already has pixels, so
			// the tint follows the bars instead of covering the gaps between them.
			ctx.globalCompositeOperation = 'source-atop';
			ctx.fillStyle = this.progressColor;
			ctx.fillRect( 0, 0, progressPx, this.height );
			ctx.globalCompositeOperation = 'source-over';
		}
	}
}
