<?php

namespace MediaWiki\Extension\AdhocTranslation\Rest;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AdhocTranslation\PageTranslator;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

class TranslatePageHandler extends SimpleHandler {

	/** @var TitleFactory */
	private TitleFactory $titleFactory;
	/** @var RevisionLookup */
	private RevisionLookup $revisionLookup;
	/** @var PageTranslator */
	private PageTranslator $translator;

	/**
	 * @param TitleFactory $titleFactory
	 * @param RevisionLookup $revisionLookup
	 * @param PageTranslator $translator
	 */
	public function __construct(
		TitleFactory $titleFactory, RevisionLookup $revisionLookup, PageTranslator $translator
	) {
		$this->titleFactory = $titleFactory;
		$this->revisionLookup = $revisionLookup;
		$this->translator = $translator;
	}

	public function execute() {
		$params = $this->getValidatedParams();
		$rev = $this->revisionLookup->getRevisionById( $params['rev_id'] );
		if ( !$rev ) {
			throw new HttpException( 'Revision not found', 404 );
		}
		$title = $this->titleFactory->castFromLinkTarget( $rev->getPageAsLinkTarget() );
		if ( !$title ) {
			throw new HttpException( 'Title not found', 404 );
		}
		$text = $params['content'];
		$status = $this->translator->getTranslationFromText( $text, $title, RequestContext::getMain()->getUser() );
		if ( !$status->isOK() ) {
			return $this->getResponseFactory()->createJson( [ 'error' => $status->getErrors() ], 400 );
		}

		return $this->getResponseFactory()->createJson( [
			'title' => $this->translateTitle( $title ),
			'text' => $status->getValue()
		] );
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	private function translateTitle( Title $title ): ?string {
		$translated = $this->translator->getTranslationFromText(
			$title->getText(), $title, RequestContext::getMain()->getUser()
		);
		if ( $translated->isOK() ) {
			return $translated->getValue();
		}
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'rev_id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return [
			'content' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedRequestTypes(): array {
		return [ 'text/plain' ];
	}

}
