import { createRoot } from '@wordpress/element';

import { App } from './App';
import './admin.scss';

const mount = document.getElementById( 'imagina-player-admin' );

if ( mount ) {
	createRoot( mount ).render( <App /> );
}
