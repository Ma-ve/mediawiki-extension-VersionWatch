<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit\Upgrade;

use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\Requirements;
use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Source\LookupFailure;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeChecker;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeReport;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeResult;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Upgrade\UpgradeChecker
 * @covers \MediaWiki\Extension\VersionWatch\Upgrade\UpgradeReport
 * @covers \MediaWiki\Extension\VersionWatch\Upgrade\UpgradeResult
 */
class UpgradeCheckerTest extends MediaWikiUnitTestCase {

	private const RELEASE = [ 'cycle' => '1.46', 'latest' => '1.46.3', 'eol' => '2027-07-31', 'lts' => false ];

	/** @var string[] Branch whose manifest was requested, by extension name */
	private array $requestedBranches = [];

	private static function extension( string $name ): Component {
		return new Component( Component::EXTENSION, $name, '1.0.0', sha1( $name ) );
	}

	private static function gerrit( string $name, string $branch = 'REL1_43' ): array {
		return [
			self::extension( $name ),
			new Repository( Repository::GERRIT, "mediawiki/extensions/$name", $branch ),
		];
	}

	/**
	 * @param array $targets
	 * @param array $manifests Gerrit manifests by extension name, as getManifests() returns them
	 * @param array $gitHubManifests
	 * @param Component[] $skipped
	 * @param Component[] $modules
	 */
	private function check(
		array $targets,
		array $manifests,
		array $gitHubManifests = [],
		array $skipped = [],
		array $modules = []
	): UpgradeReport {
		$gerritSource = $this->createMock( GerritSource::class );
		$gerritSource->method( 'getBundledCommits' )->with( '1.46.3' )
			->willReturn( [ Component::EXTENSION => [ 'Cite' => sha1( 'bundled' ) ] ] );
		$gerritSource->method( 'getManifests' )->willReturnCallback( $this->serveManifests( $manifests ) );
		$gerritSource->method( 'getCorePhpRequirement' )->with( '1.46.3' )->willReturn( '>=8.3.0' );
		$gitHubSource = $this->createMock( GitHubSource::class );
		$gitHubSource->method( 'getManifests' )->willReturnCallback( $this->serveManifests( $gitHubManifests ) );

		$checker = new UpgradeChecker( $gerritSource, $gitHubSource, new Requirements( '1.43.12', '8.2.10' ) );
		return $checker->check( self::RELEASE, 'REL1_43', $targets, $skipped, $modules );
	}

	/**
	 * A getManifests() implementation that remembers which branches were asked for.
	 *
	 * @param array $manifests By extension name
	 */
	private function serveManifests( array $manifests ): callable {
		return function ( array $targets ) use ( $manifests ) {
			$result = [];
			foreach ( $targets as $i => [ $component, $repository ] ) {
				$this->requestedBranches[$component->name] = $repository->branch;
				$result[$i] = $manifests[$component->name] ?? null;
			}
			return $result;
		};
	}

	/**
	 * @return UpgradeResult[] By component name
	 */
	private static function byName( UpgradeReport $report ): array {
		$results = [];
		foreach ( $report->results as $result ) {
			$results[$result->component->name] = $result;
		}
		return $results;
	}

	public function testStatuses() {
		$report = $this->check(
			[
				self::gerrit( 'Cite' ),
				self::gerrit( 'Echo' ),
				self::gerrit( 'Tabber' ),
				self::gerrit( 'Arrays' ),
				self::gerrit( 'Broken' ),
				self::gerrit( 'ParserFunctions', 'master' ),
			],
			[
				'Echo' => [ 'requires' => [ 'MediaWiki' => '>= 1.46' ] ],
				'Arrays' => [ 'requires' => [ 'MediaWiki' => '>= 1.47' ] ],
				'Broken' => new LookupFailure( 'versionwatch-note-request-failed' ),
				'ParserFunctions' => [ 'requires' => [ 'MediaWiki' => '>= 1.48' ] ],
			]
		);
		$results = self::byName( $report );

		$this->assertSame( UpgradeResult::BUNDLED, $results['Cite']->status );
		$this->assertSame( UpgradeResult::READY, $results['Echo']->status );
		$this->assertSame( UpgradeResult::NO_BRANCH, $results['Tabber']->status );
		$this->assertSame( [ 'REL1_46', '1.46.3' ], $results['Tabber']->noteParams );
		$this->assertSame( UpgradeResult::INCOMPATIBLE, $results['Arrays']->status );
		$this->assertSame(
			[ [ 'name' => 'MediaWiki', 'constraint' => '>= 1.47', 'installed' => '1.46.3' ] ],
			$results['Arrays']->unmetRequirements
		);
		$this->assertSame( UpgradeResult::UNKNOWN, $results['Broken']->status );
		$this->assertSame( UpgradeResult::INCOMPATIBLE, $results['ParserFunctions']->status );

		$this->assertSame( 'REL1_46', $this->requestedBranches['Echo'], 'Release branches move to the new release' );
		$this->assertSame( 'master', $this->requestedBranches['ParserFunctions'], 'Other branches stay as set' );
		$this->assertArrayNotHasKey( 'Cite', $this->requestedBranches, 'Bundled extensions are not looked up' );
	}

	public function testPhpRequirementOfRelease() {
		$report = $this->check( [], [] );

		$this->assertSame( '1.46.3', $report->version );
		$this->assertSame( '>=8.3.0', $report->phpRequirement );
		$this->assertFalse( $report->phpSupported, 'PHP 8.2 does not meet >=8.3.0' );
	}

	public function testGitHubUsesItsOwnBranch() {
		$report = $this->check(
			[ [ self::extension( 'GTag' ), new Repository( Repository::GITHUB, 'example/GTag', 'HEAD' ) ] ],
			[],
			[ 'GTag' => [ 'requires' => [ 'MediaWiki' => '>= 1.39' ] ] ]
		);

		$this->assertSame( UpgradeResult::READY, self::byName( $report )['GTag']->status );
		$this->assertSame( 'versionwatch-upgrade-note-ready-default', self::byName( $report )['GTag']->note );
		$this->assertSame( 'HEAD', $this->requestedBranches['GTag'] );
	}

	public function testSkippedAndModules() {
		$report = $this->check(
			[ self::gerrit( 'ConfirmEdit' ) ],
			[],
			[],
			[ self::extension( 'MaveVariables' ) ],
			[ new Component( Component::EXTENSION, 'ConfirmEdit/Turnstile', null, null, null, null, 'ConfirmEdit' ) ]
		);
		$results = self::byName( $report );

		$this->assertSame( UpgradeResult::SKIPPED, $results['MaveVariables']->status );
		$this->assertSame( UpgradeResult::NO_BRANCH, $results['ConfirmEdit/Turnstile']->status );
		$this->assertSame( 'versionwatch-note-part-of', $results['ConfirmEdit/Turnstile']->note );
	}

	public function testSortedAndStoredRoundTrip() {
		$report = $this->check(
			[ self::gerrit( 'Cite' ), self::gerrit( 'Tabber' ), self::gerrit( 'Echo' ) ],
			[ 'Echo' => [] ]
		);

		$this->assertSame(
			[ 'Tabber', 'Echo', 'Cite' ],
			array_map( static fn ( UpgradeResult $r ) => $r->component->name, $report->getSortedResults() )
		);
		$this->assertEquals( $report, UpgradeReport::newFromArray( $report->toArray() ) );
	}
}
