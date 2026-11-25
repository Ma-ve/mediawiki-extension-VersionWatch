<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Integration;

use MediaWiki\Content\JsonContent;
use MediaWiki\Extension\VersionWatch\RepositorySettings;
use MediaWiki\Revision\SlotRecord;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\VersionWatch\RepositorySettings
 */
class RepositorySettingsTest extends MediaWikiIntegrationTestCase {

	/**
	 * A new instance for each read, since an instance keeps what it read.
	 */
	private function newSettings(): RepositorySettings {
		$services = $this->getServiceContainer();
		return new RepositorySettings(
			$services->getRevisionLookup(),
			$services->getWikiPageFactory(),
			$services->getTitleFactory(),
			'REL1_43'
		);
	}

	private function getPageText(): string {
		$revision = $this->getServiceContainer()->getRevisionLookup()
			->getRevisionByTitle( $this->newSettings()->getTitle() );
		return $revision->getContent( SlotRecord::MAIN )->getText();
	}

	public function testEmptyWithoutPage() {
		$this->assertSame( [], $this->newSettings()->getAll() );
		$this->assertNull( $this->newSettings()->get( 'Echo' ) );
	}

	public function testSetAndRemove() {
		$sysop = $this->getTestSysop()->getAuthority();

		$this->assertStatusGood( $this->newSettings()->set( 'Tabber', false, $sysop, 'Skip Tabber' ) );
		$this->assertStatusGood(
			$this->newSettings()->set( 'GTag', [ 'github' => 'example/GTag' ], $sysop, 'Set GTag' )
		);

		$this->assertSame(
			[ 'GTag' => [ 'github' => 'example/GTag' ], 'Tabber' => false ],
			$this->newSettings()->getAll(),
			'Entries are kept and sorted by name'
		);
		$this->assertStringContainsString( "\t\"GTag\"", $this->getPageText(), 'Stored as readable JSON' );

		$this->assertStatusGood( $this->newSettings()->set( 'GTag', null, $sysop, 'Reset GTag' ) );
		$this->assertSame( [ 'Tabber' => false ], $this->newSettings()->getAll() );
	}

	public function testRemovingLastEntryLeavesEmptyObject() {
		$sysop = $this->getTestSysop()->getAuthority();
		$this->newSettings()->set( 'Tabber', false, $sysop, 'Skip Tabber' );
		$this->newSettings()->set( 'Tabber', null, $sysop, 'Reset Tabber' );

		$this->assertSame( '{}', $this->getPageText() );
	}

	public function testRequiresPermissionToEditThePage() {
		$status = $this->newSettings()->set(
			'Tabber', false, $this->getTestUser()->getAuthority(), 'Skip Tabber'
		);

		$this->assertStatusNotOK( $status );
		$this->assertSame( [], $this->newSettings()->getAll() );
	}

	public function testIgnoresPageThatIsNotJson() {
		$this->editPage(
			$this->newSettings()->getTitle(),
			new JsonContent( '"just a string"' )
		);

		$this->assertSame( [], $this->newSettings()->getAll() );
	}
}
