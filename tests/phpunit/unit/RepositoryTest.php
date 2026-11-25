<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use MediaWiki\Extension\VersionWatch\Repository;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Repository
 */
class RepositoryTest extends MediaWikiUnitTestCase {

	public static function provideWebUrl() {
		yield 'Gerrit branch' => [
			new Repository( Repository::GERRIT, 'mediawiki/extensions/Echo', 'REL1_43' ),
			'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/Echo/+/refs/heads/REL1_43',
		];
		yield 'GitHub default branch' => [
			new Repository( Repository::GITHUB, 'example/GTag', 'HEAD' ),
			'https://github.com/example/GTag',
		];
		yield 'GitHub named branch' => [
			new Repository( Repository::GITHUB, 'example/GTag', 'main' ),
			'https://github.com/example/GTag/tree/main',
		];
	}

	/**
	 * @dataProvider provideWebUrl
	 */
	public function testGetWebUrl( Repository $repository, string $expected ) {
		$this->assertSame( $expected, $repository->getWebUrl() );
	}
}
