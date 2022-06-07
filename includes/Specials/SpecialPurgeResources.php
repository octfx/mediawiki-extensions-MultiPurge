<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Specials;

use HTMLForm;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\MediaWikiServices;
use OOUIHTMLForm;
use PermissionsError;
use SpecialPage;

class SpecialPurgeResources extends SpecialPage {

	public function __construct() {
		parent::__construct( 'PurgeResources', 'editinterface' );
	}

	/**
	 * Show the page to the user
	 *
	 * @throws PermissionsError
	 */
	public function execute( $sub ) {
		$this->checkPermissions();
		$out = $this->getOutput();

		$out->setPageTitle( $this->msg( 'multipurge-form-title' ) );

		$formDescriptor = [
			'target' => [
				'section' => 'title',
				'class' => 'HTMLTextField', // same as type 'text'
				'required' => true,
				'default' => !empty( $sub ) ? $sub : null,
			],
		];

		$showPurge = false;

		if ( !empty( $sub ) ) {
			$content = $this->loadContent( $sub );

			if ( $content !== false ) {
				$formDescriptor = array_merge( $formDescriptor, $this->makeSelects( $this->parseLoads( $content ) ) );
				$showPurge = true;
			} else {
				$out->prependHTML( wfMessage( 'multipurge-special-invalid-title' )->plain() );
			}
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'purge-resources-form' );

		if ( $showPurge ) {
			$htmlForm->setSubmitText( wfMessage( 'multipurge-special-purge-submit' )->plain() );
		} else {
			$htmlForm->setSubmitText( wfMessage( 'multipurge-special-load-submit' )->plain() );
		}

		$htmlForm->setSubmitCallback( [ __CLASS__, 'trySubmit' ] );

		$htmlForm->show();
	}

	/**
	 * Load the actual raw page content for a given title
	 * Returns false on failure
	 *
	 * @param string $sub
	 * @return false|string
	 */
	private function loadContent( string $sub ) {
		if ( empty( $sub ) ) {
			return false;
		}

		$title = MediaWikiServices::getInstance()->getTitleFactory()->newFromText( $sub );

		if ( $title === null || !$title->exists() ) {
			return false;
		}

		$content = MediaWikiServices::getInstance()->getHttpRequestFactory()->get( $title->getFullURL() );

		return $content ?? false;
	}

	/**
	 * @param array $formData
	 * @param OOUIHTMLForm $form
	 * @return string|void
	 */
	public static function trySubmit( $formData, $form ) {
		if ( !isset( $formData['stylesmultiselect'] ) ) {
			$form->getOutput()->redirect(
				MediaWikiServices::getInstance()->getTitleFactory()
					->makeTitle( NS_SPECIAL, sprintf( 'PurgeResources/%s', $formData['target'] ) )
					->getFullUrlForRedirect()
			);

			return;
		}

		$urls = [];
		$server = MediaWikiServices::getInstance()->getMainConfig()->get( 'Server' );

		foreach ( $formData['stylesmultiselect'] as $selected ) {
			$urls[] = sprintf( '%s%s', $server, $selected );
		}

		foreach ( $formData['scriptsmultiselect'] as $selected ) {
			$urls[] = sprintf( '%s%s', $server, $selected );
		}

		wfDebugLog( 'MultiPurge', sprintf( 'Purging urls from Special Page: %s', json_encode( $urls ) ) );

		if ( !empty( $urls ) ) {
			$job = new MultiPurgeJob( [
				'urls' => $urls,
			] );

			if ( $job->run() ) {
				return 'multipurge-special-purge-success';
			}

			return 'multipurge-special-purge-error';
		}

		return 'multipurge-special-no-urls';
	}

	/**
	 * Parse the page content and extract load.php calls
	 *
	 * @param string $content
	 * @return array
	 */
	private function parseLoads( string $content ): array {
		// Styles
		$styleCount = preg_match_all( '/href="(\/load.php?.*)"\/>/', $content, $styles );

		// Scripts
		$scriptCount = preg_match_all( '/src="(\/load.php?.*)"><\/script>/', $content, $scripts );

		$mapper = static function ( array $data ) { return $data[0] ?? null;
		};

		if ( $styleCount !== false && $styleCount > 0 ) {
			array_shift( $styles );
			$styles = array_filter( array_map( $mapper, $styles ?? [] ) );
		}

		if ( $scriptCount !== false && $scriptCount > 0 ) {
			array_shift( $scripts );
			$scripts = array_filter( array_map( $mapper, $scripts ?? [] ) );
		}

		return [
			'scripts' => $scripts,
			'styles' => $styles,
		];
	}

	/**
	 * Map the loads to form select
	 *
	 * @param array $loads
	 * @return array[]
	 */
	private function makeSelects( array $loads ): array {
		$mapper = static function ( $data ) {
			return [ $data => $data ];
		};

		return [
			'stylesmultiselect' => [
				'section' => 'loads',
				'class' => 'HTMLMultiSelectField',
				'label-message' => 'multipurge-styles-input',
				'options' => array_map( $mapper, $loads['styles'] ),
			],
			'scriptsmultiselect' => [
				'section' => 'loads',
				'class' => 'HTMLMultiSelectField',
				'label-message' => 'multipurge-scripts-input',
				'options' => array_map( $mapper, $loads['scripts'] ),
			],
		];
	}

	protected function getGroupName() {
		return 'other';
	}
}
