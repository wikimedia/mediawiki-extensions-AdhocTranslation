<?php

use MediaWiki\Extension\AdhocTranslation\PageTranslator;
use MediaWiki\MediaWikiServices;

return [
	'AdhocTranslation.PageTranslator' => static function ( MediaWikiServices $services ) {
		return new PageTranslator(
			$services->getService( 'MWStake.DeepLTranslator' ),
			$services->getMainWANObjectCache(),
			$services->getUserOptionsLookup(),
			$services->getContentLanguage(),
			$services->getLanguageFactory()
		);
	},
];
