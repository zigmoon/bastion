/**
 * Captures the README screenshots from a running WordPress.
 *
 * Logs in, hides the WordPress chrome that is not part of the plugin, and
 * screenshots single elements at deviceScaleFactor 2 so the PNGs stay sharp
 * when GitHub scales them down.
 *
 * Run against the local install:
 *   node tools/capture-screenshots.mjs
 *
 * Environment:
 *   WP_URL       defaults to http://127.0.0.1:8080
 *   WP_USER      defaults to admin
 *   WP_PASSWORD  defaults to AdminLocal!2026
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const docs = join( here, '..', 'docs' );

const base = process.env.WP_URL || 'http://127.0.0.1:8080';
const user = process.env.WP_USER || 'admin';
const password = process.env.WP_PASSWORD || 'AdminLocal!2026';

await mkdir( docs, { recursive: true } );

const browser = await chromium.launch();
const context = await browser.newContext( {
	viewport: { width: 1440, height: 1000 },
	deviceScaleFactor: 2,
	reducedMotion: 'reduce',
} );
const page = await context.newPage();

await page.goto( `${ base }/wp-login.php`, { waitUntil: 'domcontentloaded' } );

// The sandboxed browser often refuses a synthetic click on the submit button;
// submitting the form directly sends the same request.
await page.evaluate( ( credentials ) => {
	document.querySelector( '#user_login' ).value = credentials.user;
	document.querySelector( '#user_pass' ).value = credentials.password;
	document.querySelector( '#loginform' ).submit();
}, { user, password } );

await page.waitForTimeout( 2500 );

async function clean() {
	await page.addStyleTag( {
		content: `
			#wpadminbar, #adminmenumain, #wpfooter, #screen-meta-links,
			.wp-heading-inline + .page-title-action, .update-nag,
			#wp-pointer-0, #wp-toolbar { display: none !important; }
			#wpcontent { margin-left: 0 !important; padding-left: 0 !important; }
			html.wp-toolbar { padding-top: 0 !important; }
			#wpbody-content { padding-bottom: 0 !important; }
			body { background: #fff !important; }
			* { transition: none !important; animation: none !important; }
		`,
	} );
}

async function shoot( selector, file ) {
	const element = page.locator( selector ).first();
	await element.waitFor( { state: 'visible' } );
	await page.waitForTimeout( 250 );
	await element.screenshot( { path: join( docs, file ), scale: 'device' } );

	console.log( `  docs/${ file }` );
}

async function open( tab ) {
	await page.goto( `${ base }/wp-admin/options-general.php?page=bastion#bas-panel-${ tab }`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.waitForTimeout( 900 );
	await clean();

	if ( 'overview' !== tab ) {
		await page.evaluate( ( name ) => {
			const button = [ ...document.querySelectorAll( '.bas-tab' ) ].find(
				( element ) => element.getAttribute( 'data-bas-tab' ) === name
			);

			if ( button ) {
				button.click();
			}
		}, tab );
		await page.waitForTimeout( 350 );
	}
}

for ( const [ tab, file ] of [
	[ 'overview', 'admin-overview.png' ],
	[ 'headers', 'admin-headers.png' ],
	[ 'email', 'admin-email.png' ],
	[ 'audit', 'admin-audit.png' ],
] ) {
	await open( tab );
	await shoot( '.bas-admin', file );
}

// The audit table on its own, without the tab bar.
await open( 'audit' );
const table = page.locator( '.bas-audit' ).first();

if ( await table.count() ) {
	await table.screenshot( { path: join( docs, 'audit-table.png' ), scale: 'device' } );
	console.log( '  docs/audit-table.png' );
}

// The settings screen on a phone.
await page.setViewportSize( { width: 390, height: 844 } );
await open( 'overview' );
await shoot( '.bas-admin', 'admin-mobile.png' );

await browser.close();
console.log( 'Done.' );
