<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Specials;

use MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources;
use PermissionsError;
use SpecialPageTestBase;

/**
 * @group MultiPurge
 * @group Database
 */
class SpecialPurgeResourcesTest extends SpecialPageTestBase {

	protected function newSpecialPage() {
		return new SpecialPurgeResources();
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::__construct
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::getRestriction
	 * @return void
	 */
	public function testUserWithoutEditInterfaceIsDenied() {
		$this->expectException( PermissionsError::class );

		$this->executeSpecialPage( '', null, null, $this->getTestUser()->getAuthority() );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::__construct
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::getRestriction
	 * @return void
	 */
	public function testUserWithEditInterfaceCanExecute() {
		$page = $this->newSpecialPage();

		$this->assertTrue( $page->userCanExecute( $this->getTestSysop()->getUser() ) );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testSetsPageTitle() {
		[ $html ] = $this->executeSpecialPage( '', null, null, $this->getTestSysop()->getAuthority(), true );

		$this->assertStringContainsString( '(multipurge-form-title)', $html );
	}
}
