<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use MediaWiki\Extension\VersionWatch\Requirements;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Requirements
 */
class RequirementsTest extends MediaWikiUnitTestCase {

	public static function provideUnmet() {
		yield 'no requirements' => [ null, null, [] ];
		yield 'met' => [ [ 'MediaWiki' => '>= 1.43.0', 'platform' => [ 'php' => '>= 8.1' ] ], null, [] ];
		yield 'newer MediaWiki' => [
			[ 'MediaWiki' => '>= 1.45' ],
			null,
			[ [ 'name' => 'MediaWiki', 'constraint' => '>= 1.45', 'installed' => '1.43.12' ] ],
		];
		yield 'newer PHP' => [
			[ 'platform' => [ 'php' => '>= 8.4' ] ],
			null,
			[ [ 'name' => 'PHP', 'constraint' => '>= 8.4', 'installed' => '8.3.37-1ubuntu1' ] ],
		];
		yield 'against another MediaWiki version' => [ [ 'MediaWiki' => '>= 1.45' ], '1.46.3', [] ];
		yield 'unreadable constraint' => [ [ 'MediaWiki' => 'whatever' ], null, [] ];
	}

	/**
	 * @dataProvider provideUnmet
	 */
	public function testGetUnmet( $requires, ?string $mwVersion, array $expected ) {
		$requirements = new Requirements( '1.43.12', '8.3.37-1ubuntu1' );
		$this->assertSame( $expected, $requirements->getUnmet( $requires, $mwVersion ) );
	}
}
