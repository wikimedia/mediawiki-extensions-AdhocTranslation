<?php

namespace MediaWiki\Extension\AdhocTranslation\Hook;

use BlueSpice\Discovery\Hook\BlueSpiceDiscoveryTemplateDataProviderAfterInit;
use MediaWiki\Extension\AdhocTranslation\PageTranslator;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use StatusValue;

class AddContentAction implements
	SkinTemplateNavigation__UniversalHook,
	BlueSpiceDiscoveryTemplateDataProviderAfterInit,
	BeforePageDisplayHook
{
	/**
	 * @var StatusValue|null
	 */
	private $shouldTranslate = null;

	/**
	 * @var PageTranslator
	 */
	private $pageTranslator;

	/**
	 * @param PageTranslator $pageTranslator
	 */
	public function __construct( PageTranslator $pageTranslator ) {
		$this->pageTranslator = $pageTranslator;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$this->assertCheck( $out->getTitle(), $skin->getUser() );
		if ( $this->shouldTranslate->isOk() ) {
			$out->addModules( [ 'ext.adhoctranslation.bootstrap' ] );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onBlueSpiceDiscoveryTemplateDataProviderAfterInit( $registry ): void {
		$registry->unregister( 'toolbox', 'ca-adhoc-translate' );
		$registry->register( 'actions_secondary', 'ca-adhoc-translate' );
	}

	/**
	 * @inheritDoc
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$this->assertCheck( $sktemplate->getTitle(), $sktemplate->getUser() );
		if ( $this->shouldTranslate->isOk() ) {
			$links['actions']['adhoc-translate'] = [
				'class' => '',
				'text' => $sktemplate->msg( 'adhoctranslation-ca-translate' )->plain(),
				'href' => '#',
				'position' => 30,
			];
		}
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @return void
	 */
	private function assertCheck( Title $title, User $user ): void {
		if ( $this->shouldTranslate === null ) {
			$this->shouldTranslate = $this->pageTranslator->shouldTranslate( $user, $title );
		}
	}
}
