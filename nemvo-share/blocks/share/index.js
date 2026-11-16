/**
 * Editor side of the "Shared notes and files" block. The block is rendered on the
 * server, so the editor shows a placeholder that explains what appears here.
 */
( function ( blocks, element, i18n, blockEditor ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'nemvo-share/share', {
		edit: function () {
			var props = blockEditor.useBlockProps( {
				className: 'nvs-block-placeholder',
				style: { border: '1px dashed currentColor', borderRadius: '6px', padding: '16px 20px', opacity: 0.85 },
			} );
			return el(
				'div',
				props,
				el( 'strong', null, __( 'Shared notes and files', 'nemvo-share' ) ),
				el(
					'p',
					null,
					__( 'When someone opens a secret link, the share appears here: its title, note, download buttons and settings. Add any text you like around it.', 'nemvo-share' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.blockEditor );
