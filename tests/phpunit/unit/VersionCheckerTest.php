<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\InstalledComponents;
use MediaWiki\Extension\VersionWatch\RepositoryResolver;
use MediaWiki\Extension\VersionWatch\RepositorySettings;
use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Source\ReleaseCycleSource;
use MediaWiki\Extension\VersionWatch\VersionChecker;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\VersionChecker
 * @covers \MediaWiki\Extension\VersionWatch\Component
 */
class VersionCheckerTest extends MediaWikiUnitTestCase {

	private const CITE_COMMIT = '77ddf98f9ea40e8baf57660ff5ae760831f6700a';
	private const ECHO_COMMIT = 'edd9090aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * @param Component[] $installed
	 * @param array $settings
	 * @param array $bundled What GerritSource::getBundledCommits() returns
	 * @return Component[] Components passed to GerritSource::check(), by name
	 */
	private function runWithGerritTargets( array $installed, array $settings, array $bundled ): array {
		$installedComponents = $this->createMock( InstalledComponents::class );
		$installedComponents->method( 'get' )->willReturn( $installed );

		$repositorySettings = $this->createMock( RepositorySettings::class );
		$repositorySettings->method( 'getResolver' )->willReturn( new RepositoryResolver( $settings, 'REL1_43' ) );

		$releaseCycleSource = $this->createMock( ReleaseCycleSource::class );
		$releaseCycleSource->method( 'check' )->willReturn( [] );
		$gitHubSource = $this->createMock( GitHubSource::class );
		$gitHubSource->method( 'check' )->willReturn( [] );

		$checked = [];
		$gerritSource = $this->createMock( GerritSource::class );
		$gerritSource->method( 'getBundledCommits' )->with( '1.43.12' )->willReturn( $bundled );
		$gerritSource->method( 'check' )->willReturnCallback( static function ( array $targets ) use ( &$checked ) {
			foreach ( $targets as [ $component ] ) {
				$checked[$component->name] = $component;
			}
			return [];
		} );

		( new VersionChecker(
			$installedComponents, $repositorySettings, $releaseCycleSource, $gerritSource, $gitHubSource
		) )->run();
		return $checked;
	}

	public function testBundledExtensionUsesCommitPinnedByRelease() {
		$checked = $this->runWithGerritTargets(
			[
				new Component( Component::CORE, 'MediaWiki', '1.43.12' ),
				new Component( Component::EXTENSION, 'Cite' ),
				new Component( Component::EXTENSION, 'Echo', null, self::ECHO_COMMIT ),
			],
			[],
			[ Component::EXTENSION => [
				'Cite' => self::CITE_COMMIT,
				'Echo' => 'ffffffffffffffffffffffffffffffffffffffff',
			] ]
		);

		$this->assertSame( self::CITE_COMMIT, $checked['Cite']->commit );
		$this->assertSame( '1.43.12', $checked['Cite']->bundledWith );
		$this->assertSame( self::ECHO_COMMIT, $checked['Echo']->commit, 'A git checkout keeps its own commit' );
		$this->assertNull( $checked['Echo']->bundledWith );
	}

	public function testExtensionNotInReleaseIsUnchanged() {
		$checked = $this->runWithGerritTargets(
			[
				new Component( Component::CORE, 'MediaWiki', '1.43.12' ),
				new Component( Component::EXTENSION, 'Theme', '2.2.3' ),
			],
			[],
			[ Component::EXTENSION => [ 'Cite' => self::CITE_COMMIT ] ]
		);

		$this->assertNull( $checked['Theme']->commit );
		$this->assertNull( $checked['Theme']->bundledWith );
	}

	public function testBundledCommitsAreOnlyLookedUpWhenNeeded() {
		$installedComponents = $this->createMock( InstalledComponents::class );
		$installedComponents->method( 'get' )->willReturn( [
			new Component( Component::CORE, 'MediaWiki', '1.43.12' ),
			new Component( Component::EXTENSION, 'Echo', null, self::ECHO_COMMIT ),
		] );
		$repositorySettings = $this->createMock( RepositorySettings::class );
		$repositorySettings->method( 'getResolver' )->willReturn( new RepositoryResolver( [], 'REL1_43' ) );
		$gerritSource = $this->createMock( GerritSource::class );
		$gerritSource->expects( $this->never() )->method( 'getBundledCommits' );
		$gerritSource->method( 'check' )->willReturn( [] );

		( new VersionChecker(
			$installedComponents,
			$repositorySettings,
			$this->createMock( ReleaseCycleSource::class ),
			$gerritSource,
			$this->createMock( GitHubSource::class )
		) )->run();
	}

	public function testModuleGetsStatusOfContainingExtension() {
		$confirmEdit = new Component( Component::EXTENSION, 'ConfirmEdit', '1.6.0', self::ECHO_COMMIT );
		$turnstile = new Component(
			Component::EXTENSION, 'ConfirmEdit/Turnstile', null, null, null, null, 'ConfirmEdit'
		);

		$installedComponents = $this->createMock( InstalledComponents::class );
		$installedComponents->method( 'get' )->willReturn( [
			new Component( Component::CORE, 'MediaWiki', '1.43.12' ),
			$confirmEdit,
			$turnstile,
		] );
		$repositorySettings = $this->createMock( RepositorySettings::class );
		$repositorySettings->method( 'getResolver' )->willReturn( new RepositoryResolver( [], 'REL1_43' ) );
		$gerritSource = $this->createMock( GerritSource::class );
		$gerritSource->method( 'check' )->willReturnCallback( function ( array $targets ) use ( $confirmEdit ) {
			$this->assertSame( [ 'ConfirmEdit' ], array_map( static fn ( $t ) => $t[0]->name, $targets ),
				'The module is not looked up on its own' );
			return [ new CheckResult(
				$confirmEdit, CheckResult::OUTDATED, '1.6.0', '20261124000000', null, null, [], self::CITE_COMMIT
			) ];
		} );

		$report = ( new VersionChecker(
			$installedComponents,
			$repositorySettings,
			$this->createMock( ReleaseCycleSource::class ),
			$gerritSource,
			$this->createMock( GitHubSource::class )
		) )->run();

		$result = array_values( array_filter(
			$report->results,
			static fn ( CheckResult $r ) => $r->component === $turnstile
		) )[0];
		$this->assertSame( CheckResult::OUTDATED, $result->status );
		$this->assertSame( '1.6.0', $result->latest );
		$this->assertSame( self::CITE_COMMIT, $result->latestCommit );
		$this->assertSame( 'versionwatch-note-part-of', $result->note );
		$this->assertSame( [ 'ConfirmEdit' ], $result->noteParams );
	}

	/**
	 * @param CheckResult $coreResult What the release cycle check found for MediaWiki
	 * @param array|null $securityFixes What GerritSource::getCoreSecurityFixes() returns
	 * @return CheckResult The result for MediaWiki in the report
	 */
	private function checkCore( CheckResult $coreResult, ?array $securityFixes ): CheckResult {
		$installedComponents = $this->createMock( InstalledComponents::class );
		$installedComponents->method( 'get' )->willReturn( [ $coreResult->component ] );
		$repositorySettings = $this->createMock( RepositorySettings::class );
		$repositorySettings->method( 'getResolver' )->willReturn( new RepositoryResolver( [], 'REL1_43' ) );
		$releaseCycleSource = $this->createMock( ReleaseCycleSource::class );
		$releaseCycleSource->method( 'check' )->willReturn( [ $coreResult ] );
		$gerritSource = $this->createMock( GerritSource::class );
		$gerritSource->method( 'check' )->willReturn( [] );
		$gerritSource->method( 'getCoreSecurityFixes' )->with( '1.43.12', '1.43.13' )->willReturn( $securityFixes );

		$report = ( new VersionChecker(
			$installedComponents,
			$repositorySettings,
			$releaseCycleSource,
			$gerritSource,
			$this->createMock( GitHubSource::class )
		) )->run();
		return $report->results[0];
	}

	public function testCoreReleaseWithSecurityFixes() {
		$core = new Component( Component::CORE, 'MediaWiki', '1.43.12' );
		$fix = [
			'sha' => self::CITE_COMMIT,
			'subject' => 'SECURITY: Fix',
			'url' => 'https://example.org',
			'security' => true,
		];

		$result = $this->checkCore(
			new CheckResult( component: $core, status: CheckResult::OUTDATED, latest: '1.43.13', eol: '2027-12-31' ),
			[ $fix ]
		);

		$this->assertSame( CheckResult::SECURITY, $result->status );
		$this->assertSame( 'versionwatch-note-core-security', $result->note );
		$this->assertSame( [ '1.43.13', 1 ], $result->noteParams );
		$this->assertSame( [ $fix ], $result->changes );
		$this->assertSame( '2027-12-31', $result->eol );
	}

	public function testCoreReleaseWithoutSecurityFixesStaysOutdated() {
		$core = new Component( Component::CORE, 'MediaWiki', '1.43.12' );

		$result = $this->checkCore(
			new CheckResult( component: $core, status: CheckResult::OUTDATED, latest: '1.43.13' ),
			[]
		);

		$this->assertSame( CheckResult::OUTDATED, $result->status );
	}
}
