<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Integration;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Report;
use PermissionsError;
use SpecialPageTestBase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @group Database
 * @covers \MediaWiki\Extension\VersionWatch\Specials\SpecialVersionWatch
 */
class SpecialVersionWatchTest extends SpecialPageTestBase {

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'MainObjectStash', new HashBagOStuff() );
	}

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'VersionWatch' );
	}

	public function testRestrictedToTheRight() {
		$page = $this->newSpecialPage();

		$this->assertSame( 'versionwatch', $page->getRestriction() );
		$this->assertTrue( $page->isRestricted() );
		$this->assertFalse( $page->userCanExecute( $this->getTestUser()->getUser() ) );
		$this->assertTrue( $page->userCanExecute( $this->getTestSysop()->getUser() ) );
	}

	public function testRefusesUsersWithoutTheRight() {
		$this->expectException( PermissionsError::class );
		$this->executeSpecialPage( '', null, 'qqx', $this->getTestUser()->getAuthority() );
	}

	public function testWithoutReport() {
		[ $html ] = $this->executeSpecialPage( '', null, 'qqx', $this->getTestSysop()->getAuthority() );

		$this->assertStringContainsString( '(versionwatch-no-report)', $html );
	}

	public function testShowsReport() {
		$this->getServiceContainer()->get( 'VersionWatch.ReportStore' )->save( new Report( '20261127100000', [
			new CheckResult(
				component: new Component( Component::EXTENSION, 'ConfirmEdit', '1.6.0', sha1( 'installed' ) ),
				status: CheckResult::SECURITY,
				note: 'versionwatch-note-security-changes',
				noteParams: [ 3, 'REL1_43', 1 ],
				changes: [ [
					'sha' => sha1( 'fix' ),
					'subject' => 'SECURITY: Fix <script>',
					'url' => 'https://example.org/fix',
					'security' => true,
				] ],
				downloadUrl: 'https://example.org/download'
			),
		] ) );

		[ $html ] = $this->executeSpecialPage( '', null, 'qqx', $this->getTestSysop()->getAuthority() );

		$this->assertStringContainsString( '(versionwatch-attention-security', $html );
		$this->assertStringContainsString( '(versionwatch-status-security)', $html );
		$this->assertStringContainsString( 'SECURITY: Fix &lt;script&gt;', $html, 'Commit subjects are escaped' );
		$this->assertStringContainsString( 'target="_blank"', $html );
	}
}
