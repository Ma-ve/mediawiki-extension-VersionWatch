<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit\Source;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Source\ReleaseCycleSource;
use MediaWiki\Extension\VersionWatch\Tests\MockHttpTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Source\ReleaseCycleSource
 * @covers \MediaWiki\Extension\VersionWatch\Source\LookupFailure
 */
class ReleaseCycleSourceTest extends MediaWikiUnitTestCase {
	use MockHttpTrait;

	private const MEDIAWIKI_CYCLES = [
		[ 'cycle' => '1.44', 'eol' => '2026-07-31', 'latest' => '1.44.6', 'latestReleaseDate' => '2026-06-29' ],
		[ 'cycle' => '1.43', 'eol' => '2027-12-31', 'latest' => '1.43.12', 'latestReleaseDate' => '2026-11-20' ],
	];

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( '20261125000000' );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	private function checkOne( Component $component, array $responses ): CheckResult {
		$source = new ReleaseCycleSource( $this->newHttpRequestFactory( $responses ) );
		$results = $source->check( [ $component ] );
		$this->assertCount( 1, $results );
		return $results[0];
	}

	private function mediaWikiResponse(): array {
		return [
			'https://endoflife.date/api/mediawiki.json' => [ 'body' => json_encode( self::MEDIAWIKI_CYCLES ) ],
		];
	}

	public function testLatestPatchReleaseIsCurrent() {
		$result = $this->checkOne(
			new Component( Component::CORE, 'MediaWiki', '1.43.12' ),
			$this->mediaWikiResponse()
		);

		$this->assertSame( CheckResult::CURRENT, $result->status );
		$this->assertSame( '1.43.12', $result->latest );
		$this->assertSame( '20261120000000', $result->latestDate );
		$this->assertSame( '2027-12-31', $result->eol );
		$this->assertSame( 'versionwatch-note-supported-until', $result->note );
		$this->assertSame( [ '2027-12-31' ], $result->noteParams );
	}

	public function testOlderPatchReleaseIsOutdated() {
		$result = $this->checkOne(
			new Component( Component::CORE, 'MediaWiki', '1.43.9' ),
			$this->mediaWikiResponse()
		);

		$this->assertSame( CheckResult::OUTDATED, $result->status );
		$this->assertSame( '1.43.12', $result->latest );
		$this->assertSame( 'https://www.mediawiki.org/wiki/Download', $result->downloadUrl );
	}

	public function testBranchPastEndOfLifeIsUnsupported() {
		$result = $this->checkOne(
			new Component( Component::CORE, 'MediaWiki', '1.44.6' ),
			$this->mediaWikiResponse()
		);

		$this->assertSame( CheckResult::UNSUPPORTED, $result->status );
		$this->assertSame( 'versionwatch-note-eol-passed', $result->note );
		$this->assertSame( [ '2026-07-31' ], $result->noteParams );
	}

	public function testBooleanEndOfLife() {
		$result = $this->checkOne(
			new Component( Component::PHP, 'PHP', '7.4.33' ),
			[
				'https://endoflife.date/api/php.json' => [ 'body' => json_encode( [
					[ 'cycle' => '7.4', 'eol' => true, 'latest' => '7.4.33' ],
				] ) ],
			]
		);

		$this->assertSame( CheckResult::UNSUPPORTED, $result->status );
		$this->assertNull( $result->eol );
		$this->assertSame( 'versionwatch-note-eol', $result->note );
	}

	public function testVendorSuffixIsIgnored() {
		$result = $this->checkOne(
			new Component( Component::DATABASE, 'MariaDB', '10.11.19-MariaDB-cll-lve-log' ),
			[
				'https://endoflife.date/api/mariadb.json' => [ 'body' => json_encode( [
					[ 'cycle' => '10.11', 'eol' => '2028-02-16', 'latest' => '10.11.19' ],
					[ 'cycle' => '10.1', 'eol' => '2020-10-17', 'latest' => '10.1.48' ],
				] ) ],
			]
		);

		$this->assertSame( CheckResult::CURRENT, $result->status );
		$this->assertSame( '2028-02-16', $result->eol );
	}

	public function testSingleNumberCycle() {
		$result = $this->checkOne(
			new Component( Component::DATABASE, 'PostgreSQL', '16.2' ),
			[
				'https://endoflife.date/api/postgresql.json' => [ 'body' => json_encode( [
					[ 'cycle' => '17', 'eol' => '2029-11-08', 'latest' => '17.4' ],
					[ 'cycle' => '16', 'eol' => '2028-11-09', 'latest' => '16.8' ],
				] ) ],
			]
		);

		$this->assertSame( CheckResult::OUTDATED, $result->status );
		$this->assertSame( '16.8', $result->latest );
	}

	public function testUnknownCycle() {
		$result = $this->checkOne(
			new Component( Component::CORE, 'MediaWiki', '1.47.0-alpha' ),
			$this->mediaWikiResponse()
		);

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-unknown-cycle', $result->note );
	}

	public function testUnknownProductMakesNoRequest() {
		$result = $this->checkOne( new Component( Component::DATABASE, 'Oracle', '23.1' ), [] );

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-unknown-product', $result->note );
	}

	public function testFailedRequest() {
		$result = $this->checkOne(
			new Component( Component::CORE, 'MediaWiki', '1.43.12' ),
			[
				'https://endoflife.date/api/mediawiki.json' => [ 'code' => 0, 'error' => 'Connection timed out' ],
			]
		);

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-request-failed', $result->note );
		$this->assertSame( [ 'endoflife.date', 'Connection timed out' ], $result->noteParams );
	}

	public function testNewestMediaWikiRelease() {
		$source = new ReleaseCycleSource( $this->newHttpRequestFactory( [
			'https://endoflife.date/api/mediawiki.json' => [ 'body' => json_encode( [
				[ 'cycle' => '1.47', 'releaseDate' => '2026-12-15', 'eol' => '2028-01-01', 'latest' => '1.47.0' ],
				[ 'cycle' => '1.46', 'releaseDate' => '2026-06-30', 'eol' => '2027-07-31', 'latest' => '1.46.3' ],
				[
					'cycle' => '1.43',
					'releaseDate' => '2024-12-21',
					'eol' => '2027-12-31',
					'latest' => '1.43.12',
					'lts' => true,
				],
				[ 'cycle' => '1.42', 'releaseDate' => '2024-06-27', 'eol' => '2025-06-30', 'latest' => '1.42.7' ],
			] ) ],
		] ) );

		$this->assertSame(
			[ 'cycle' => '1.46', 'latest' => '1.46.3', 'eol' => '2027-07-31', 'lts' => false ],
			$source->getNewestMediaWikiRelease(),
			'Announced but unreleased branches are not offered'
		);
	}

	public function testReleaseDataIsDownloadedOnce() {
		$source = new ReleaseCycleSource( $this->newHttpRequestFactory( $this->mediaWikiResponse() ) );
		$source->check( [ new Component( Component::CORE, 'MediaWiki', '1.43.12' ) ] );
		$source->getNewestMediaWikiRelease();

		$this->assertCount( 1, $this->sentRequests );
	}
}
