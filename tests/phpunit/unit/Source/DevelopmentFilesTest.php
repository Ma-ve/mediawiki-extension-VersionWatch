<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit\Source;

use MediaWiki\Extension\VersionWatch\Source\DevelopmentFiles;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Source\DevelopmentFiles
 */
class DevelopmentFilesTest extends MediaWikiUnitTestCase {

	public static function provideContains() {
		yield 'npm lock file' => [ 'package-lock.json', true ];
		yield 'composer.json' => [ 'composer.json', true ];
		yield 'linter configuration' => [ '.phpcs.xml', true ];
		yield 'dot directory' => [ '.phan/config.php', true ];
		yield 'tests' => [ 'tests/phpunit/HooksTest.php', true ];
		yield 'Gruntfile' => [ 'Gruntfile.js', true ];
		yield 'extension.json' => [ 'extension.json', false ];
		yield 'PHP code' => [ 'src/Hooks.php', false ];
		yield 'messages' => [ 'i18n/en.json', false ];
		yield 'JavaScript module' => [ 'modules/ext.foo.js', false ];
		yield 'package.json in a subdirectory' => [ 'modules/lib/package.json', false ];
	}

	/**
	 * @dataProvider provideContains
	 */
	public function testContains( string $path, bool $expected ) {
		$this->assertSame( $expected, DevelopmentFiles::contains( $path ) );
	}

	public function testContainsAll() {
		$this->assertTrue( DevelopmentFiles::containsAll( [ 'package.json', 'package-lock.json' ] ) );
		$this->assertFalse( DevelopmentFiles::containsAll( [ 'package.json', 'extension.json' ] ) );
	}
}
