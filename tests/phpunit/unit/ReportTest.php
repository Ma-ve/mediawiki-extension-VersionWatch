<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Report
 * @covers \MediaWiki\Extension\VersionWatch\CheckResult
 * @covers \MediaWiki\Extension\VersionWatch\Component
 */
class ReportTest extends MediaWikiUnitTestCase {

	private static function result( string $name, string $status ): CheckResult {
		return new CheckResult( new Component( Component::EXTENSION, $name ), $status );
	}

	public function testSortedBySeverityThenName() {
		$report = new Report( '20261125000000', [
			self::result( 'ReplaceText', CheckResult::CURRENT ),
			self::result( 'echo', CheckResult::OUTDATED ),
			self::result( 'Validator', CheckResult::UNKNOWN ),
			self::result( 'Arrays', CheckResult::OUTDATED ),
			self::result( 'MaveVariables', CheckResult::SKIPPED ),
		] );

		$names = array_map(
			static fn ( CheckResult $r ) => $r->component->name,
			$report->getSortedResults()
		);
		$this->assertSame( [ 'Arrays', 'echo', 'Validator', 'ReplaceText', 'MaveVariables' ], $names );
	}

	public function testArrayRoundTrip() {
		$report = new Report( '20261125000000', [
			new CheckResult(
				new Component( Component::EXTENSION, 'Echo', '1.0', 'edd9090', '20220609072700', '1.43.12' ),
				CheckResult::OUTDATED,
				'f6d697b',
				'20261124043535',
				null,
				'versionwatch-note-commits-behind',
				[ 3, 'REL1_43' ]
			),
		] );

		$this->assertEquals( $report, Report::newFromArray( $report->toArray() ) );
	}

	public function testReadsReportsStoredBeforeBundledWithExisted() {
		$component = [
			'type' => 'extension',
			'name' => 'Echo',
			'version' => null,
			'commit' => null,
			'commitDate' => null,
		];

		$this->assertNull( Component::newFromArray( $component )->bundledWith );
	}
}
