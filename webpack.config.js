/**
 * Three bundles: the front-end player, the block editor UI and the admin screen.
 *
 * Everything else — Babel, TypeScript, SCSS, the dependency manifest each PHP
 * side reads — comes from @wordpress/scripts' defaults.
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		frontend: path.resolve( __dirname, 'assets/src/frontend/index.ts' ),
		editor: path.resolve( __dirname, 'assets/src/editor/index.tsx' ),
		admin: path.resolve( __dirname, 'assets/src/admin/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		/*
		 * Not 'auto'. Webpack's automatic path reads `document.currentScript`
		 * at startup and *throws* when there is no script URL to read — which
		 * is what happens the moment an optimisation plugin inlines the bundle
		 * into the page. The player would die before rendering anything.
		 *
		 * An empty string emits no detection code at all; the real value is set
		 * at runtime from what WordPress tells us, in frontend/public-path.ts.
		 */
		publicPath: '',
	},
};
