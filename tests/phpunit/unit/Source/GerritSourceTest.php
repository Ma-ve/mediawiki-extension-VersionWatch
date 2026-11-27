<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit\Source;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Tests\MockHttpTrait;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Source\GerritSource
 * @covers \MediaWiki\Extension\VersionWatch\Source\GitSource
 */
class GerritSourceTest extends MediaWikiUnitTestCase {
	use MockHttpTrait;

	private const BASE = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/Echo/';
	private const HEAD_URL = self::BASE . '+/refs/heads/REL1_43?format=JSON';
	private const LOCAL_COMMIT = 'edd9090aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const LOG_URL = self::BASE . '+log/' . self::LOCAL_COMMIT . '..REL1_43?format=JSON&name-status=1&n=10000';
	private const MANIFEST_URL = self::BASE . '+/refs/heads/REL1_43/extension.json?format=TEXT';

	private const HEAD_RESPONSE = [
		'commit' => 'f6d697bd699652fd6c8196e06141e52fec2d025c',
		'committer' => [ 'time' => 'Tue Nov 24 05:35:35 2026 +0100' ],
	];

	/**
	 * @param Component $component
	 * @param array $responses Responses by URL; the branch's extension.json defaults to missing
	 */
	private function checkOne( Component $component, array $responses ): CheckResult {
		$responses += [ self::MANIFEST_URL => [ 'code' => 404 ] ];
		$source = new GerritSource( $this->newHttpRequestFactory( $responses ), [ 'Translation updater bot' ] );
		$repository = new Repository( Repository::GERRIT, 'mediawiki/extensions/Echo', 'REL1_43' );
		$results = $source->check( [ [ $component, $repository ] ] );
		$this->assertCount( 1, $results );
		return $results[0];
	}

	/**
	 * Gitiles prefixes its JSON to prevent cross-site script inclusion.
	 */
	private static function gitilesJson( array $data ): array {
		return [ 'body' => ")]}'\n" . json_encode( $data ) ];
	}

	/**
	 * @param string ...$authors One commit per author, each changing a PHP file
	 */
	private static function logOf( string ...$authors ): array {
		return self::logWithFiles( ...array_map( static fn ( $name ) => [ $name, [ 'src/Hooks.php' ] ], $authors ) );
	}

	/**
	 * @param array ...$commits Author, changed files and, optionally, the commit message
	 */
	private static function logWithFiles( array ...$commits ): array {
		return self::gitilesJson( [
			'log' => array_map( static fn ( array $commit, int $i ) => [
				'commit' => sha1( "commit $i" ),
				'message' => $commit[2] ?? "Change $i\n\nChange-Id: I$i",
				'author' => [ 'name' => $commit[0] ],
				'tree_diff' => array_map(
					static fn ( $path ) => [ 'old_path' => $path, 'new_path' => $path ],
					$commit[1]
				),
			], $commits, array_keys( $commits ) ),
		] );
	}

	private static function gitCheckout(): Component {
		return new Component( Component::EXTENSION, 'Echo', null, self::LOCAL_COMMIT, '20220609072700' );
	}

	public function testCommitsBehindExcludingIgnoredAuthors() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logOf( 'Translation updater bot', 'Umherirrender', 'Translation updater bot' ),
			self::MANIFEST_URL => [ 'body' => base64_encode( json_encode( [ 'version' => '1.2.0' ] ) ) ],
		] );

		$this->assertSame( CheckResult::OUTDATED, $result->status );
		$this->assertSame( '1.2.0', $result->latest, 'The version on the branch is shown' );
		$this->assertSame( self::HEAD_RESPONSE['commit'], $result->latestCommit );
		$this->assertSame( '20261124043535', $result->latestDate );
		$this->assertSame( 'versionwatch-note-commits-behind', $result->note );
		$this->assertSame( [ 1, 'REL1_43' ], $result->noteParams );
	}

	public function testOnlyIgnoredCommitsBehindIsCurrent() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logOf( 'Translation updater bot', 'Translation updater bot' ),
		] );

		$this->assertSame( CheckResult::CURRENT, $result->status );
		$this->assertSame( 'versionwatch-note-only-ignored-commits', $result->note );
		$this->assertSame( [ 2 ], $result->noteParams );
	}

	public function testCommitsOnlyChangingDevelopmentFilesDoNotCount() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logWithFiles(
				[ 'libraryupgrader', [ 'package.json', 'package-lock.json' ] ],
				[ 'libraryupgrader', [ 'composer.json', '.phpcs.xml' ] ],
				[ 'Umherirrender', [ 'tests/phpunit/HooksTest.php' ] ],
				[ 'Umherirrender', [ 'src/Hooks.php', 'tests/phpunit/HooksTest.php' ] ]
			),
		] );

		$this->assertSame( CheckResult::OUTDATED, $result->status );
		$this->assertSame( [ 1, 'REL1_43' ], $result->noteParams, 'Only the commit changing src/ counts' );
	}

	public function testDeletedFileUsesItsOldPath() {
		$log = self::gitilesJson( [ 'log' => [ [
			'commit' => sha1( 'deletion' ),
			'message' => 'Remove unused class',
			'author' => [ 'name' => 'Reedy' ],
			'tree_diff' => [ [ 'old_path' => 'src/Unused.php', 'new_path' => '/dev/null' ] ],
		] ] ] );
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => $log,
		] );

		$this->assertSame( CheckResult::OUTDATED, $result->status );
	}

	public function testAtBranchHead() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logOf(),
		] );

		$this->assertSame( CheckResult::CURRENT, $result->status );
		$this->assertNull( $result->note );
		$this->assertNull( $result->latest, 'Without a version upstream, only the commit is known' );
		$this->assertSame( self::HEAD_RESPONSE['commit'], $result->latestCommit );
	}

	public function testMissingBranch() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => [ 'code' => 404 ],
			self::LOG_URL => [ 'code' => 404 ],
		] );

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-no-branch', $result->note );
		$this->assertSame( [ 'REL1_43' ], $result->noteParams );
	}

	public function testMissingRepository() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => [ 'code' => 401 ],
			self::LOG_URL => [ 'code' => 401 ],
		] );

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-repo-not-found', $result->note );
		$this->assertSame( [ 'mediawiki/extensions/Echo', 'gerrit.wikimedia.org' ], $result->noteParams );
	}

	public function testCommitUnknownUpstream() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => [ 'code' => 404 ],
		] );

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-commit-not-found', $result->note );
		$this->assertSame( [ 'edd9090' ], $result->noteParams );
	}

	public function testVersionComparedWithoutGit() {
		$result = $this->checkOne( new Component( Component::EXTENSION, 'Echo', '0.5.3' ), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::MANIFEST_URL => [ 'body' => base64_encode( json_encode( [ 'version' => '0.5.4' ] ) ) ],
		] );

		$this->assertSame( CheckResult::OUTDATED, $result->status );
		$this->assertSame( '0.5.4', $result->latest );
		$this->assertSame( self::HEAD_RESPONSE['commit'], $result->latestCommit );
	}

	public function testUpstreamWithoutVersion() {
		$result = $this->checkOne( new Component( Component::EXTENSION, 'Echo', '0.5.3' ), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::MANIFEST_URL => [ 'body' => base64_encode( json_encode( [ 'name' => 'Echo' ] ) ) ],
		] );

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-no-upstream-version', $result->note );
	}

	public function testNeitherCommitNorVersion() {
		$result = $this->checkOne( new Component( Component::EXTENSION, 'Echo' ), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
		] );

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-no-version', $result->note );
	}

	public function testSkinUsesSkinManifest() {
		$base = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/skins/Vector/+/refs/heads/REL1_43';
		$source = new GerritSource( $this->newHttpRequestFactory( [
			"$base?format=JSON" => self::gitilesJson( self::HEAD_RESPONSE ),
			"$base/skin.json?format=TEXT" => [ 'body' => base64_encode( json_encode( [ 'version' => '1.0.0' ] ) ) ],
		] ), [] );

		$results = $source->check( [ [
			new Component( Component::SKIN, 'Vector', '1.0.0' ),
			new Repository( Repository::GERRIT, 'mediawiki/skins/Vector', 'REL1_43' ),
		] ] );

		$this->assertSame( CheckResult::CURRENT, $results[0]->status );
	}

	public function testBundledCommits() {
		$base = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/+/refs/tags/1.43.12';
		$source = new GerritSource( $this->newHttpRequestFactory( [
			"$base/extensions?format=JSON" => self::gitilesJson( [ 'entries' => [
				[ 'type' => 'blob', 'name' => '.gitignore', 'id' => 'df80c2a660b7069b6f9db8c91a2d9cd88e6a08fb' ],
				[ 'type' => 'commit', 'name' => 'Cite', 'id' => '77ddf98f9ea40e8baf57660ff5ae760831f6700a' ],
			] ] ),
			"$base/skins?format=JSON" => self::gitilesJson( [ 'entries' => [
				[ 'type' => 'commit', 'name' => 'Vector', 'id' => '31a11ab6c9a9e39539b2a2d220a964ff20ccac4f' ],
			] ] ),
		] ), [] );

		$this->assertSame(
			[
				Component::EXTENSION => [ 'Cite' => '77ddf98f9ea40e8baf57660ff5ae760831f6700a' ],
				Component::SKIN => [ 'Vector' => '31a11ab6c9a9e39539b2a2d220a964ff20ccac4f' ],
			],
			$source->getBundledCommits( '1.43.12' )
		);
	}

	public function testNoBundledCommitsWithoutReleaseTag() {
		$base = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/+/refs/tags/1.44.0-alpha';
		$source = new GerritSource( $this->newHttpRequestFactory( [
			"$base/extensions?format=JSON" => [ 'code' => 404 ],
			"$base/skins?format=JSON" => [ 'code' => 404 ],
		] ), [] );

		$this->assertSame(
			[ Component::EXTENSION => [], Component::SKIN => [] ],
			$source->getBundledCommits( '1.44.0-alpha' )
		);
	}

	public function testSecurityFixRaisesStatusAndIsListedFirst() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logWithFiles(
				[ 'Umherirrender', [ 'src/Hooks.php' ], 'Use strict comparison' ],
				[ 'Translation updater bot', [ 'i18n/nl.json' ], 'Localisation updates' ],
				[ 'Reedy', [ 'src/Api/ApiEchoNotifications.php' ], "SECURITY: Check read permission\n\nBug: T1" ]
			),
		] );

		$this->assertSame( CheckResult::SECURITY, $result->status );
		$this->assertSame( 'versionwatch-note-security-changes', $result->note );
		$this->assertSame( [ 2, 'REL1_43', 1 ], $result->noteParams );
		$this->assertSame(
			[ 'SECURITY: Check read permission', 'Use strict comparison' ],
			array_column( $result->changes, 'subject' ),
			'Security fixes come first; ignored commits are not listed'
		);
		$this->assertTrue( $result->changes[0]['security'] );
		$this->assertSame( self::BASE . '+/' . sha1( 'commit 2' ), $result->changes[0]['url'] );
		$this->assertSame( self::BASE . '+log/' . self::LOCAL_COMMIT . '..REL1_43', $result->changesUrl );
	}

	public function testNoChangesUrlWhenUpToDate() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logOf( 'Translation updater bot' ),
		] );

		$this->assertSame( [], $result->changes );
		$this->assertNull( $result->changesUrl );
	}

	public function testCoreSecurityFixes() {
		$url = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/'
			. '+log/refs/tags/1.43.12..refs/tags/1.43.13?format=JSON&n=10000';
		$source = new GerritSource( $this->newHttpRequestFactory( [
			$url => self::logWithFiles(
				[ 'Reedy', [ 'includes/Defines.php' ], 'Release 1.43.13' ],
				[ 'Reedy', [ 'includes/Title/Title.php' ], 'SECURITY: Title: Encode leading slash' ]
			),
		] ), [] );

		$fixes = $source->getCoreSecurityFixes( '1.43.12', '1.43.13' );

		$this->assertSame( [ 'SECURITY: Title: Encode leading slash' ], array_column( $fixes, 'subject' ) );
	}

	public function testCoreSecurityFixesWithoutReleaseTag() {
		$url = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/'
			. '+log/refs/tags/1.44.0-alpha..refs/tags/1.43.13?format=JSON&n=10000';
		$source = new GerritSource( $this->newHttpRequestFactory( [ $url => [ 'code' => 404 ] ] ), [] );

		$this->assertNull( $source->getCoreSecurityFixes( '1.44.0-alpha', '1.43.13' ) );
	}

	public function testDownloadFromExtensionDistributor() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logOf( 'Umherirrender' ),
		] );

		$this->assertSame( 'https://www.mediawiki.org/wiki/Special:ExtensionDistributor/Echo', $result->downloadUrl );
	}

	public function testNoDownloadWhenUpToDate() {
		$result = $this->checkOne( self::gitCheckout(), [
			self::HEAD_URL => self::gitilesJson( self::HEAD_RESPONSE ),
			self::LOG_URL => self::logOf(),
		] );

		$this->assertNull( $result->downloadUrl );
	}

	public function testNoDownloadForOtherGerritProjects() {
		$base = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/EchoFork/';
		$source = new GerritSource( $this->newHttpRequestFactory( [
			$base . '+/refs/heads/REL1_43?format=JSON' => self::gitilesJson( self::HEAD_RESPONSE ),
			$base . '+log/' . self::LOCAL_COMMIT . '..REL1_43?format=JSON&name-status=1&n=10000'
				=> self::logOf( 'Reedy' ),
			$base . '+/refs/heads/REL1_43/extension.json?format=TEXT' => [ 'code' => 404 ],
		] ), [] );

		$results = $source->check( [ [
			self::gitCheckout(),
			new Repository( Repository::GERRIT, 'mediawiki/extensions/EchoFork', 'REL1_43' ),
		] ] );

		$this->assertSame( CheckResult::OUTDATED, $results[0]->status );
		$this->assertNull( $results[0]->downloadUrl, 'ExtensionDistributor only knows the extension by its own name' );
	}

	public function testSkinDownloadFromSkinDistributor() {
		$base = 'https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/skins/Vector/+/refs/heads/REL1_43';
		$source = new GerritSource( $this->newHttpRequestFactory( [
			"$base?format=JSON" => self::gitilesJson( self::HEAD_RESPONSE ),
			"$base/skin.json?format=TEXT" => [ 'body' => base64_encode( json_encode( [ 'version' => '1.1.0' ] ) ) ],
		] ), [] );

		$results = $source->check( [ [
			new Component( Component::SKIN, 'Vector', '1.0.0' ),
			new Repository( Repository::GERRIT, 'mediawiki/skins/Vector', 'REL1_43' ),
		] ] );

		$this->assertSame(
			'https://www.mediawiki.org/wiki/Special:SkinDistributor/Vector',
			$results[0]->downloadUrl
		);
	}
}
