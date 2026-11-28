<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use InvalidArgumentException;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\InvalidSettingException;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\RepositoryResolver;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\RepositoryResolver
 */
class RepositoryResolverTest extends MediaWikiUnitTestCase {

	public static function provideReleaseBranch() {
		yield 'patch release' => [ '1.43.12', 'REL1_43' ];
		yield 'pre-release' => [ '1.45.0-alpha', 'REL1_45' ];
	}

	/**
	 * @dataProvider provideReleaseBranch
	 */
	public function testReleaseBranchFor( string $version, string $expected ) {
		$this->assertSame( $expected, RepositoryResolver::releaseBranchFor( $version ) );
	}

	public function testReleaseBranchForInvalidVersion() {
		$this->expectException( InvalidArgumentException::class );
		RepositoryResolver::releaseBranchFor( 'master' );
	}

	public static function provideResolve() {
		yield 'extension defaults to Gerrit release branch' => [
			[],
			new Component( Component::EXTENSION, 'Echo' ),
			new Repository( Repository::GERRIT, 'mediawiki/extensions/Echo', 'REL1_43' ),
		];
		yield 'skin defaults to skins project' => [
			[],
			new Component( Component::SKIN, 'Vector' ),
			new Repository( Repository::GERRIT, 'mediawiki/skins/Vector', 'REL1_43' ),
		];
		yield 'branch override keeps default project' => [
			[ 'Echo' => [ 'branch' => 'master' ] ],
			new Component( Component::EXTENSION, 'Echo' ),
			new Repository( Repository::GERRIT, 'mediawiki/extensions/Echo', 'master' ),
		];
		yield 'GitHub defaults to the default branch' => [
			[ 'GTag' => [ 'github' => 'example/GTag' ] ],
			new Component( Component::EXTENSION, 'GTag' ),
			new Repository( Repository::GITHUB, 'example/GTag', 'HEAD' ),
		];
		yield 'VersionWatch defaults to GitHub' => [
			[],
			new Component( Component::EXTENSION, 'VersionWatch' ),
			new Repository( Repository::GITHUB, 'Ma-ve/mediawiki-extension-VersionWatch', 'HEAD' ),
		];
		yield 'branch override keeps a known repository' => [
			[ 'VersionWatch' => [ 'branch' => 'develop' ] ],
			new Component( Component::EXTENSION, 'VersionWatch' ),
			new Repository( Repository::GITHUB, 'Ma-ve/mediawiki-extension-VersionWatch', 'develop' ),
		];
		yield 'a setting replaces a known repository' => [
			[ 'VersionWatch' => [ 'gerrit' => 'mediawiki/extensions/VersionWatch' ] ],
			new Component( Component::EXTENSION, 'VersionWatch' ),
			new Repository( Repository::GERRIT, 'mediawiki/extensions/VersionWatch', 'REL1_43' ),
		];
		yield 'known repositories are for extensions only' => [
			[],
			new Component( Component::SKIN, 'VersionWatch' ),
			new Repository( Repository::GERRIT, 'mediawiki/skins/VersionWatch', 'REL1_43' ),
		];
		yield 'skipped' => [
			[ 'MaveVariables' => false ],
			new Component( Component::EXTENSION, 'MaveVariables' ),
			null,
		];
	}

	/**
	 * @dataProvider provideResolve
	 */
	public function testResolve( array $overrides, Component $component, ?Repository $expected ) {
		$resolver = new RepositoryResolver( $overrides, 'REL1_43' );
		$this->assertEquals( $expected, $resolver->resolve( $component ) );
	}

	public static function provideInvalidSetting() {
		yield 'not an object' => [ 'mediawiki/extensions/Echo' ];
		yield 'unknown key' => [ [ 'gitlab' => 'example/Echo' ] ];
		yield 'both hosts' => [ [ 'gerrit' => 'mediawiki/extensions/Echo', 'github' => 'example/Echo' ] ];
		yield 'not a string' => [ [ 'gerrit' => 12 ] ];
		yield 'empty string' => [ [ 'branch' => '' ] ];
	}

	/**
	 * @dataProvider provideInvalidSetting
	 */
	public function testInvalidSetting( $setting ) {
		$resolver = new RepositoryResolver( [ 'Echo' => $setting ], 'REL1_43' );
		$this->expectException( InvalidSettingException::class );
		$resolver->resolve( new Component( Component::EXTENSION, 'Echo' ) );
	}

	public function testDefault() {
		$resolver = new RepositoryResolver( [ 'Echo' => [ 'github' => 'example/Echo' ] ], 'REL1_43' );
		$this->assertEquals(
			new Repository( Repository::GERRIT, 'mediawiki/extensions/Echo', 'REL1_43' ),
			$resolver->getDefault( new Component( Component::EXTENSION, 'Echo' ) )
		);
	}
}
