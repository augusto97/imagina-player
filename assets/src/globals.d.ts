/**
 * Ambient declarations for imports webpack resolves but TypeScript does not.
 */

declare module '*.scss';
declare module '*.css';

// @wordpress/block-editor does not ship type definitions; the surface used here
// is small and covered by the props declared at each call site.
declare module '@wordpress/block-editor';
