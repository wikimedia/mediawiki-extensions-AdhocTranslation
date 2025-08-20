<?php

namespace MediaWiki\Extension\AdhocTranslation\Tests;

use Exception;
use MediaWiki\Extension\AdhocTranslation\PageTranslator;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Revision\RevisionRenderer;
use MediaWiki\Settings\Config\ArrayConfigBuilder;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;
use MWStake\MediaWiki\Component\DeeplTranslator\DeepLTranslator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use StatusValue;
use Wikimedia\ObjectCache\BagOStuff;

class PageTranslatorTest extends TestCase {

	/**
	 * @param string $userOptionLang
	 * @param string $expectedTargetLang
	 * @param string|null $expectedError
	 * @return void
	 * @throws Exception
	 * @covers       \MediaWiki\Extension\AdhocTranslation\PageTranslator::shouldTranslate
	 * @covers       \MediaWiki\Extension\AdhocTranslation\PageTranslator::getTranslationFromText
	 * @dataProvider provideShouldTranslate
	 */
	public function testShouldTranslate(
		string $userOptionLang, string $expectedTargetLang, ?string $expectedError = null
	) {
		$uolMock = $this->createMock( UserOptionsLookup::class );
		$uolMock->method( 'getOption' )->willReturnCallback(
			static function ( $user, $option ) use ( $userOptionLang ) {
				if ( $option === 'language' ) {
					return $userOptionLang;
				}
				return null;
			}
		);

		$config = ( new ArrayConfigBuilder() )
			->set( 'DeeplTranslateServiceAuth', 'mocked-token' )
			->set( 'DeeplTranslateServiceUrl', 'https://api.deepl.com/' )
			->build();
		$requestFactoryMock = $this->createMock( HttpRequestFactory::class );

		$deeplMock = $this->getMockBuilder( DeepLTranslator::class )
			->setConstructorArgs( [ $config, $requestFactoryMock ] )
			->onlyMethods( [ 'getSupportedLanguages', 'translateText' ] )
			->getMock();
		$deeplMock->method( 'getSupportedLanguages' )->willReturn( Status::newGood( [
			(object)[ 'language' => 'EN', 'supports_formality' => false ],
			(object)[ 'language' => 'DE', 'supports_formality' => true ],
			(object)[ 'language' => 'ZH', 'supports_formality' => false ],
			(object)[ 'language' => 'RO', 'supports_formality' => false ],
		] ) );
		$deeplMock->method( 'translateText' )->willReturn( StatusValue::newGood( 'dummy' ) );
		if ( !$expectedError ) {
			$expectedTarget = $expectedTargetLang;
			if ( $expectedTarget === 'DE-FORMAL' ) {
				$expectedTarget = 'DE';
			}
			$deeplMock->expects( $this->once() )->method( 'translateText' )->with(
				'dummy',
				'EN',
				$expectedTarget
			);
		}

		$languageFactoryMock = $this->getLanguageFactoryMock();

		$userMock = $this->createMock( UserIdentity::class );
		$userMock->method( 'getId' )->willReturn( 1 );
		$userMock->method( 'getName' )->willReturn( 'TestUser' );

		$titleMock = $this->createMock( Title::class );
		$titleMock->method( 'getPageLanguage' )->willReturn( $languageFactoryMock->getLanguage( 'en' ) );
		$subtitleMock = $this->createMock( Title::class );
		$subtitleMock->method( 'getPageLanguage' )->willReturn( $languageFactoryMock->getLanguage( 'ro' ) );
		$subtitleMock->method( 'exists' )->willReturn( true );
		$titleMock->method( 'hasSubpages' )->willReturn( true );
		$titleMock->method( 'getSubpage' )->willReturnCallback( static function ( $subpage ) use ( $subtitleMock ) {
			if ( $subpage === 'ro' ) {
				return $subtitleMock;
			}
			return null;
		} );

		$cacheMock = $this->createMock( BagOStuff::class );
		$cacheMock->method( 'makeKey' )->willReturn( 'test' );

		$translator = new PageTranslator(
			$deeplMock,
			$cacheMock,
			$uolMock,
			$languageFactoryMock->getLanguage( 'en' ),
			$languageFactoryMock
		);

		$status = $translator->shouldTranslate( $userMock, $titleMock );
		$this->assertInstanceOf( StatusValue::class, $status );
		if ( $expectedError ) {
			$this->assertFalse( $status->isOK() );
			$this->assertEquals( $expectedError, $status->getErrors()[0]['message'] );
		} else {
			$this->assertTrue( $status->isOK() );
			$this->assertEquals( 'EN', $status->getValue()['source'] );
			$this->assertEquals( $expectedTargetLang, $status->getValue()['target'] );

			$translator->getTranslationFromText( 'dummy', $titleMock, $userMock );
		}
	}

	public function provideShouldTranslate() {
		return [
			[
				'userOptionLang' => 'de',
				'expectedTargetLang' => 'DE',
				'expectedError' => null
			],
			[
				'userOptionLang' => 'de-formal',
				'expectedTargetLang' => 'DE-FORMAL',
				'expectedError' => null
			],
			[
				'userOptionLang' => 'zh-hant-hk',
				'expectedTargetLang' => 'ZH',
				'expectedError' => null
			],
			[
				// this lang is not registered, so defaults to content lang => EN
				'userOptionLang' => 'cs',
				'expectedTargetLang' => 'EN',
				'expectedError' => 'adhoctranslation-same-lang'
			],
			[
				// Does exist, but not supported by DeepL
				'userOptionLang' => 'bg',
				'expectedTargetLang' => 'BG',
				'expectedError' => 'adhoctranslation-target-lang-not-supported'
			],
			[
				// Can be translated, all supported, but local page with translation alraedy exists
				'userOptionLang' => 'ro',
				'expectedTargetLang' => 'RO',
				'expectedError' => 'adhoctranslation-target-exists'
			]
		];
	}

	/**
	 * @return RevisionRenderer|MockObject
	 */
	protected function getRevisionRendererMock() {
		$poMock = $this->createMock( ParserOutput::class );
		$poMock->method( 'getText' )->willReturn( 'Hello, world!' );

		$renderedRevisionMock = $this->createMock( RenderedRevision::class );
		$renderedRevisionMock->method( 'getRevisionParserOutput' )->willReturn( $poMock );

		$revisionRendererMock = $this->createMock( RevisionRenderer::class );
		$revisionRendererMock->method( 'getRenderedRevision' )->willReturn( $renderedRevisionMock );
		return $revisionRendererMock;
	}

	/**
	 * @return LanguageFactory|MockObject
	 */
	protected function getLanguageFactoryMock() {
		$langEn = $this->createMock( Language::class );
		$langEn->method( 'getCode' )->willReturn( 'en' );
		$langDe = $this->createMock( Language::class );
		$langDe->method( 'getCode' )->willReturn( 'de' );
		$langDeFormal = $this->createMock( Language::class );
		$langDeFormal->method( 'getCode' )->willReturn( 'de-formal' );
		$langZhHantHK = $this->createMock( Language::class );
		$langZhHantHK->method( 'getCode' )->willReturn( 'zh-hant-hk' );
		$langZh = $this->createMock( Language::class );
		$langZh->method( 'getCode' )->willReturn( 'zh' );
		$langBg = $this->createMock( Language::class );
		$langBg->method( 'getCode' )->willReturn( 'bg' );
		$langRo = $this->createMock( Language::class );
		$langRo->method( 'getCode' )->willReturn( 'ro' );

		$langFactoryMock = $this->createMock( LanguageFactory::class );
		$langFactoryMock->method( 'getLanguage' )->willReturnCallback( static function ( $code ) use (
			$langEn, $langDe, $langDeFormal, $langZhHantHK, $langZh, $langBg, $langRo
		) {
			switch ( $code ) {
				case 'en':
					return $langEn;
				case 'de':
					return $langDe;
				case 'de-formal':
					return $langDeFormal;
				case 'zh-hant-hk':
					return $langZhHantHK;
				case 'zh':
					return $langZh;
				case 'bg':
					return $langBg;
				case 'ro':
					return $langRo;
			}
			return null;
		} );
		$langFactoryMock->method( 'getParentLanguage' )->willReturnCallback( static function ( $code ) use (
			$langEn, $langDe, $langDeFormal, $langZhHantHK, $langZh
		) {
			switch ( $code ) {
				case 'zh-hant-hk':
					return $langZh;
			}
			return null;
		} );

		return $langFactoryMock;
	}
}
