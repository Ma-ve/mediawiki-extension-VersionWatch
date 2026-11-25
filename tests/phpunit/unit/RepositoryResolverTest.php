<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use InvalidArgumentException;
use MediaWiki\Extension\VersionWatch\Component;
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

	public function testInvalidOverride() {
		$resolver = new RepositoryResolver( [ 'Echo' => 'mediawiki/extensions/Echo' ], 'REL1_43' );
		$this->expectException( InvalidArgumentException::class );
		$resolver->resolve( new Component( Component::EXTENSION, 'Echo' ) );
	}
}
