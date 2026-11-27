<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use MediaWiki\Extension\VersionWatch\CheckRunner;
use MediaWiki\Extension\VersionWatch\Notifications\UpdateNotifier;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWiki\Extension\VersionWatch\ReportStore;
use MediaWiki\Extension\VersionWatch\VersionChecker;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\CheckRunner
 */
class CheckRunnerTest extends MediaWikiUnitTestCase {

	public function testStoresReportAndNotifiesAgainstThePreviousOne() {
		$previous = new Report( '20261126000000', [] );
		$current = new Report( '20261127000000', [] );

		$versionChecker = $this->createMock( VersionChecker::class );
		$versionChecker->method( 'run' )->willReturn( $current );
		$reportStore = $this->createMock( ReportStore::class );
		$reportStore->method( 'get' )->willReturn( $previous );
		$reportStore->expects( $this->once() )->method( 'save' )->with( $current );
		$notifier = $this->createMock( UpdateNotifier::class );
		$notifier->expects( $this->once() )->method( 'notify' )->with( $previous, $current );

		$this->assertSame( $current, ( new CheckRunner( $versionChecker, $reportStore, $notifier ) )->run() );
	}

	public function testWorksWithoutNotifier() {
		$versionChecker = $this->createMock( VersionChecker::class );
		$versionChecker->method( 'run' )->willReturn( new Report( '20261127000000', [] ) );

		$report = ( new CheckRunner( $versionChecker, $this->createMock( ReportStore::class ) ) )->run();

		$this->assertSame( '20261127000000', $report->timestamp );
	}
}
