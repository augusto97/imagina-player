/**
 * Two bundles: the front-end player and the block editor UI.
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
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
