<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit\Notifications;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Notifications\UpdateNotifier;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Notifications\UpdateNotifier
 */
class UpdateNotifierTest extends MediaWikiUnitTestCase {

	/**
	 * @param array<string,string> $statuses Status by extension name
	 */
	private static function report( array $statuses ): Report {
		$results = [];
		foreach ( $statuses as $name => $status ) {
			$partOf = str_contains( $name, '/' ) ? explode( '/', $name )[0] : null;
			$results[] = new CheckResult(
				component: new Component( Component::EXTENSION, $name, null, null, null, null, $partOf ),
				status: $status
			);
		}
		return new Report( '20261127000000', $results );
	}

	/**
	 * @return string[]
	 */
	private static function newsNames( ?Report $previous, Report $current ): array {
		return array_map(
			static fn ( CheckResult $r ) => $r->component->name,
			( new UpdateNotifier() )->getNews( $previous, $current )
		);
	}

	public function testFirstCheckReportsEverythingThatNeedsAttention() {
		$current = self::report( [
			'Echo' => CheckResult::OUTDATED,
			'Cite' => CheckResult::CURRENT,
			'ConfirmEdit' => CheckResult::SECURITY,
			'ConfirmEdit/Turnstile' => CheckResult::SECURITY,
		] );

		$this->assertSame(
			[ 'ConfirmEdit', 'Echo' ],
			self::newsNames( null, $current ),
			'Security first; modules are reported through their extension'
		);
	}

	public function testKnownProblemsAreNotRepeated() {
		$previous = self::report( [ 'Echo' => CheckResult::OUTDATED, 'Cite' => CheckResult::CURRENT ] );
		$current = self::report( [ 'Echo' => CheckResult::OUTDATED, 'Cite' => CheckResult::OUTDATED ] );

		$this->assertSame( [ 'Cite' ], self::newsNames( $previous, $current ) );
	}

	public function testBecomingASecurityUpdateIsNews() {
		$previous = self::report( [ 'Echo' => CheckResult::OUTDATED ] );
		$current = self::report( [ 'Echo' => CheckResult::SECURITY ] );

		$this->assertSame( [ 'Echo' ], self::newsNames( $previous, $current ) );
	}

	public function testResolvedProblemsAreNotNews() {
		$previous = self::report( [ 'Echo' => CheckResult::SECURITY ] );
		$current = self::report( [ 'Echo' => CheckResult::CURRENT ] );

		$this->assertSame( [], self::newsNames( $previous, $current ) );
	}
}
