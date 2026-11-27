<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit\Source;

use MediaWiki\Extension\VersionWatch\Source\SecurityFixes;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Source\SecurityFixes
 */
class SecurityFixesTest extends MediaWikiUnitTestCase {

	public static function provideSubjects() {
		yield 'Wikimedia convention' => [ 'SECURITY: Check read permission', true ];
		yield 'lowercase' => [ 'security: check read permission', true ];
		yield 'leading space' => [ ' SECURITY: Check read permission', true ];
		yield 'mentioned later' => [ 'Revert "SECURITY: Check read permission"', false ];
		yield 'without colon' => [ 'Security improvements for the API', false ];
	}

	/**
	 * @dataProvider provideSubjects
	 */
	public function testIsSecurityFix( string $subject, bool $expected ) {
		$this->assertSame( $expected, SecurityFixes::isSecurityFix( $subject ) );
	}
}
