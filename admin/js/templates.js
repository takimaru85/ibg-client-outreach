/**
 * IBG Client Outreach – template editor helpers.
 *
 * - Click-to-insert merge tags into the last focused field (TinyMCE, the
 *   HTML textarea, the subject or the plain-text box).
 * - Preview iframe: switch sample contact and HTML/plain-text.
 */
( function () {
	'use strict';

	var lastTarget = null; // { type: 'tinymce' | 'field', el }

	function trackFocus( el ) {
		el.addEventListener( 'focus', function () {
			lastTarget = { type: 'field', el: el };
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.ibg-tag-target' ), trackFocus );

	var htmlTextarea = document.getElementById( 'ibg-template-body' );
	if ( htmlTextarea ) {
		trackFocus( htmlTextarea );
	}

	function tinymceEditor() {
		if ( ! window.tinymce ) {
			return null;
		}
		var ed = window.tinymce.get( 'ibg-template-body' );
		return ed && ! ed.isHidden() ? ed : null;
	}

	if ( window.tinymce ) {
		window.tinymce.on( 'AddEditor', function ( e ) {
			e.editor.on( 'focus', function () {
				lastTarget = { type: 'tinymce', el: e.editor };
			} );
		} );
	}

	function insertAtCursor( field, text ) {
		var start = field.selectionStart || 0;
		var end   = field.selectionEnd || 0;
		var value = field.value;
		field.value = value.slice( 0, start ) + text + value.slice( end );
		field.selectionStart = field.selectionEnd = start + text.length;
		field.focus();
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.ibg-tag-insert' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var tag = btn.getAttribute( 'data-tag' );

			if ( lastTarget && 'tinymce' === lastTarget.type && tinymceEditor() ) {
				lastTarget.el.insertContent( tag );
				lastTarget.el.focus();
				return;
			}
			if ( lastTarget && 'field' === lastTarget.type ) {
				insertAtCursor( lastTarget.el, tag );
				return;
			}
			var ed = tinymceEditor();
			if ( ed ) {
				ed.insertContent( tag );
				ed.focus();
			} else if ( htmlTextarea ) {
				insertAtCursor( htmlTextarea, tag );
			}
		} );
	} );

	/* Preview */

	var frame   = document.getElementById( 'ibg-preview-frame' );
	var select  = document.getElementById( 'ibg-preview-contact' );
	var toggle  = document.getElementById( 'ibg-preview-toggle' );

	function reloadPreview() {
		if ( ! frame || ! select ) {
			return;
		}
		var base   = select.getAttribute( 'data-preview-base' );
		var url    = new window.URL( base, window.location.href );
		var format = toggle ? toggle.getAttribute( 'data-format' ) : 'html';
		url.searchParams.set( 'contact', select.value );
		url.searchParams.set( 'format', format );
		frame.src = url.toString();
	}

	if ( select ) {
		select.addEventListener( 'change', reloadPreview );
	}
	if ( toggle ) {
		var i18n = ( window.ibgOutreachTemplates && window.ibgOutreachTemplates.i18n ) || { showHtml: 'Show HTML', showText: 'Show plain text' };
		toggle.addEventListener( 'click', function () {
			var showingHtml = 'html' === toggle.getAttribute( 'data-format' );
			toggle.setAttribute( 'data-format', showingHtml ? 'text' : 'html' );
			toggle.textContent = showingHtml ? i18n.showHtml : i18n.showText;
			reloadPreview();
		} );
	}
}() );
