<?php

namespace MediaWiki\Extension\AdhocTranslation;

use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;
use MWStake\MediaWiki\Component\DeeplTranslator\DeepLTranslator;
use StatusValue;
use Throwable;
use Wikimedia\ObjectCache\WANObjectCache;

class PageTranslator {

	/** @var DeepLTranslator */
	private DeepLTranslator $translator;
	/** @var WANObjectCache */
	private $cache;
	/** @var UserOptionsLookup */
	private UserOptionsLookup $userOptionLookup;
	/** @var Language */
	private Language $contentLanguage;
	/** @var LanguageFactory */
	private LanguageFactory $languageFactory;

	/**
	 * @param DeepLTranslator $translator
	 * @param WANObjectCache $cache
	 * @param UserOptionsLookup $uol
	 * @param Language $contentLanguage
	 * @param LanguageFactory $languageFactory
	 */
	public function __construct(
		DeepLTranslator $translator, $cache,
		UserOptionsLookup $uol, Language $contentLanguage, LanguageFactory $languageFactory
	) {
		$this->translator = $translator;
		$this->cache = $cache;
		$this->userOptionLookup = $uol;
		$this->contentLanguage = $contentLanguage;
		$this->languageFactory = $languageFactory;
	}

	/**
	 * @param UserIdentity $user
	 * @return string
	 */
	public function getUserLanguage( UserIdentity $user ): string {
		try {
			$userLangOption = $this->userOptionLookup->getOption( $user, 'language' );
			$userLang = $this->languageFactory->getLanguage( $userLangOption );
			$parent = $this->languageFactory->getParentLanguage( $userLang->getCode() );
			while ( $parent ) {
				$newParent = $this->languageFactory->getParentLanguage( $parent->getCode() );
				if ( !$newParent || $parent->getCode() === $newParent->getCode() ) {
					return strtoupper( $parent->getCode() );
				}
				$parent = $newParent;
			}
			return strtoupper( $userLang->getCode() );
		} catch ( Throwable $ex ) {
			return strtoupper( $this->contentLanguage->getCode() );
		}
	}

	/**
	 * @param string $text
	 * @param Title $title
	 * @param UserIdentity $forUser
	 * @return StatusValue
	 */
	public function getTranslationFromText( string $text, Title $title, UserIdentity $forUser ): StatusValue {
		$targetsStatus = $this->getTargets( $title, $forUser );
		if ( !$targetsStatus->isGood() ) {
			return $targetsStatus;
		}
		$targets = $targetsStatus->getValue();

		return $this->translator->translateText( $text, $targets['source'], $targets['target'], [
			'formality' => $targets['formality'],
		] );
	}

	/**
	 * @param Title $title
	 * @param UserIdentity $forUser
	 * @return StatusValue
	 */
	private function getTargets( Title $title, UserIdentity $forUser ): StatusValue {
		$shouldTranslate = $this->shouldTranslate( $forUser, $title );
		if ( !$shouldTranslate->isOK() ) {
			return $shouldTranslate;
		}

		$languages = $shouldTranslate->getValue();
		[ $sourceLang, $formal ] = $this->getBase( $languages['source'] );
		[ $targetLang, $targetFormal ]  = $this->getBase( $languages['target'] );

		return StatusValue::newGood( [
			'source' => $sourceLang,
			'target' => $targetLang,
			'formality' => $targetFormal
		] );
	}

	/**
	 * @param UserIdentity $user
	 * @param Title $title
	 * @return StatusValue
	 */
	public function shouldTranslate( UserIdentity $user, Title $title ): StatusValue {
		if ( !$this->translator->isConfigured() ) {
			return StatusValue::newFatal( 'adhoctranslation-not-configured' );
		}
		$targetLang = $this->getUserLanguage( $user );
		$sourceLang = $this->getPageLanguage( $title );
		if ( $sourceLang === $targetLang ) {
			return StatusValue::newFatal( 'adhoctranslation-same-lang' );
		}
		if ( $this->hasTargetLangPage( $title, $targetLang ) ) {
			return StatusValue::newFatal( 'adhoctranslation-target-exists' );
		}
		if ( !$this->isLanguageSupported( $sourceLang, 'source' ) ) {
			return StatusValue::newFatal( 'adhoctranslation-source-lang-not-supported', $targetLang );
		}
		if ( !$this->isLanguageSupported( $targetLang, 'target' ) ) {
			return StatusValue::newFatal( 'adhoctranslation-target-lang-not-supported', $targetLang );
		}

		return StatusValue::newGood( [ 'source' => $sourceLang, 'target' => $targetLang ] );
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	private function getPageLanguage( Title $title ): string {
		return strtoupper( $title->getPageLanguage()->getCode() );
	}

	/**
	 * @param Title $title
	 * @param string $targetLang
	 * @return bool
	 */
	private function hasTargetLangPage( Title $title, string $targetLang ) {
		$targetSubpage = strtolower( $targetLang );
		if ( $title->hasSubpages() ) {
			$subpage = $title->getSubpage( $targetSubpage );
			if ( $subpage && $subpage->exists() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $lang
	 * @param string $type
	 * @return bool
	 */
	private function isLanguageSupported( string $lang, string $type ): bool {
		if ( $lang === 'EN' && $type === 'target' ) {
			// DeepL no longer supports just "EN" for target, it must be a variant
			// Since we usually don't know the variant, imply "EN-US"
			$lang = 'EN-US';
		}
		$cc = $this->cache->makeKey( 'adhoctranslation', 'supported-languages', $type );
		if ( $this->cache->get( $cc ) ) {
			$list = $this->cache->get( $cc );
		} else {
			$status = $this->translator->getSupportedLanguages( $type );
			if ( $status->isOK() ) {
				$list = $status->getValue();
				$this->cache->set( $cc, $list, WANObjectCache::TTL_DAY );
			} else {
				return false;
			}
		}

		$isFormal = str_ends_with( $lang, '-FORMAL' );
		$base = $isFormal ? substr( $lang, 0, -7 ) : $lang;
		foreach ( $list as $langItem ) {
			if (
				$langItem->language === $lang ||
				( $langItem->language === $base && $isFormal && $langItem->supports_formality )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $lang
	 * @return array
	 */
	private function getBase( string $lang ): array {
		if ( str_ends_with( $lang, '-FORMAL' ) ) {
			return [ substr( $lang, 0, -7 ), true ];
		}
		return [ $lang, false ];
	}

}
