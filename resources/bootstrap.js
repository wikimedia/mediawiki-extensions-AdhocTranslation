window.ext.adhocTranslation = window.ext.adhocTranslation || {};
ext.adhocTranslation.Translator = function ( cfg ) {
	this.$btn = cfg.$btn;
	this.$content = cfg.$content;
	this.$title = cfg.$title;
	this.mode = cfg.mode;
	this.revision = cfg.revision;

	this.state = 'ready';

	this.message = new OO.ui.MessageWidget();
	this.message.$element.hide();
	this.message.$element.insertBefore( this.$content );

	this.$original = this.$content.html();
	this.originalTitle = this.$title.length ? this.$title.text() : '';
	this.$btn.on( 'click', this.translate.bind( this ) );
};

OO.initClass( ext.adhocTranslation.Translator );

ext.adhocTranslation.Translator.prototype.translate = function ( e ) {
	e.preventDefault();
	if ( this.state !== 'ready' ) {
		return;
	}
	this.showMessage( 'info', mw.msg( 'adhoctranslation-ui-translating' ) );
	this.state = 'processing';
	$.ajax( {
		method: 'POST',
		url: mw.util.wikiScript( 'rest' ) + '/adhoc-translation/translate_page/' + this.revision,
		contentType: 'text/plain',
		data: this.mode === 'client' ? this.$content.html() : ''
	} ).done( ( data ) => {
		if ( data.hasOwnProperty( 'text' ) ) {
			this.state = 'translated';
			this.$content.html( data.text );
			if ( data.title && this.$title.length ) {
				this.$title.text( data.title );
			}

			this.showMessage( 'info', mw.msg( 'adhoctranslation-ui-translated' ) );
			this.addShowOriginalButton();
		} else {
			this.state = 'ready';
			this.showMessage( 'error', mw.msg( 'adhoctranslation-ui-error' ) );
		}
	} ).fail( () => {
		this.state = 'ready';
		this.showMessage( 'info', mw.msg( 'adhoctranslation-ui-error' ) );
	} );
};

ext.adhocTranslation.Translator.prototype.showOriginal = function ( e ) {
	e.preventDefault();
	this.$content.html( this.$original );
	if ( this.$title.length ) {
		this.$title.html( this.originalTitle );
	}
	this.message.$element.hide();
};

ext.adhocTranslation.Translator.prototype.addShowOriginalButton = function () {
	const $showOriginalButton = $( '<a>' ).text( mw.msg( 'adhoctranslation-ui-original' ) ).attr( 'href', '#' );
	$showOriginalButton.on( 'click', this.showOriginal.bind( this ) );
	$showOriginalButton.css( 'margin-left', '1em' );
	this.message.$element.find( '.oo-ui-labelElement-label' ).append( $showOriginalButton );
};

ext.adhocTranslation.Translator.prototype.showMessage = function ( type, text ) {
	this.message.setType( type );
	this.message.setLabel( text );
	this.message.$element.show();
	this.state = 'ready';
};

$( () => {
	const $btn = $( '#ca-adhoc-translate' );
	if ( !$btn.length ) {
		return;
	}
	const revision = mw.config.get( 'wgRevisionId' );
	if ( !revision ) {
		$btn.remove();
		return;
	}
	new ext.adhocTranslation.Translator( { // eslint-disable-line no-new
		$btn: $btn,
		$content: $( '#mw-content-text>.mw-parser-output' ),
		$title: $( '#firstHeading>.mw-page-title-main' ),
		mode: 'client',
		revision: revision
	} );
} );
