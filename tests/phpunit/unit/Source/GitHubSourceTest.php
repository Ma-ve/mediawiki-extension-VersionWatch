<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit\Source;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Tests\MockHttpTrait;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VersionWatch\Source\GitHubSource
 * @covers \MediaWiki\Extension\VersionWatch\Source\GitSource
 */
class GitHubSourceTest extends MediaWikiUnitTestCase {
	use MockHttpTrait;

	private const LOCAL_COMMIT = '0123456789abcdef0123456789abcdef01234567';
	private const HEAD_URL = 'https://api.github.com/repos/example/GTag/commits/HEAD';
	private const COMPARE_URL = 'https://api.github.com/repos/example/GTag/compare/' . self::LOCAL_COMMIT . '...HEAD';
	private const MANIFEST_URL = 'https://raw.githubusercontent.com/example/GTag/HEAD/extension.json';

	private const HEAD_RESPONSE = [
		'sha' => 'fedcba9876543210fedcba9876543210fedcba98',
		'commit' => [ 'committer' => [ 'date' => '2026-11-01T12:00:00Z' ] ],
	];

	/**
	 * @param array $responses Responses by URL; the branch's extension.json defaults to missing
	 * @param string|null $token
	 */
	private function check( array $responses, ?string $token = null ): CheckResult {
		$responses += [ self::MANIFEST_URL => [ 'code' => 404 ] ];
		$source = new GitHubSource( $this->newHttpRequestFactory( $responses ), [ 'Translation updater bot' ], $token );
		$results = $source->check( [ [
			new Component( Component::EXTENSION, 'GTag', '1.3.0', self::LOCAL_COMMIT ),
			new Repository( Repository::GITHUB, 'example/GTag', 'HEAD' ),
		] ] );
		return $results[0];
	}

	private static function json( array $data ): array {
		return [ 'body' => json_encode( $data ) ];
	}

	public function testTruncatedComparisonCountsUnlistedCommits() {
		// GitHub lists at most 250 commits but reports the full count in ahead_by.
		$result = $this->check( [
			self::HEAD_URL => self::json( self::HEAD_RESPONSE ),
			self::COMPARE_URL => self::json( [
				'ahead_by' => 300,
				'commits' => [
					[
						'sha' => sha1( 'first' ),
						'commit' => [
							'message' => 'Localisation updates',
							'author' => [ 'name' => 'Translation updater bot' ],
						],
					],
					[
						'sha' => sha1( 'second' ),
						'commit' => [
							'message' => 'Localisation updates',
							'author' => [ 'name' => 'Translation updater bot' ],
						],
					],
				],
			] ),
		] );

		$this->assertSame( CheckResult::OUTDATED, $result->status );
		$this->assertNull( $result->latest );
		$this->assertSame( self::HEAD_RESPONSE['sha'], $result->latestCommit );
		$this->assertSame( '20261101120000', $result->latestDate );
		$this->assertSame( [ 298, 'HEAD' ], $result->noteParams );
	}

	public function testTokenIsSentToApiOnly() {
		$this->check( [
			self::HEAD_URL => self::json( self::HEAD_RESPONSE ),
			self::COMPARE_URL => self::json( [ 'ahead_by' => 0, 'commits' => [] ] ),
		], 'secret' );

		$this->assertSame( 'Bearer secret', $this->sentRequests[self::HEAD_URL]['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'headers', $this->sentRequests[self::MANIFEST_URL] );
	}

	public function testVersionOnBranchIsShown() {
		$result = $this->check( [
			self::HEAD_URL => self::json( self::HEAD_RESPONSE ),
			self::COMPARE_URL => self::json( [ 'ahead_by' => 0, 'commits' => [] ] ),
			self::MANIFEST_URL => self::json( [ 'name' => 'GTag', 'version' => '1.4.0' ] ),
		] );

		$this->assertSame( CheckResult::CURRENT, $result->status, 'A git checkout is compared by commit' );
		$this->assertSame( '1.4.0', $result->latest );
	}

	public function testRateLimit() {
		$limited = [ 'code' => 403, 'headers' => [ 'x-ratelimit-remaining' => '0' ] ];
		$result = $this->check( [ self::HEAD_URL => $limited, self::COMPARE_URL => $limited ] );

		$this->assertSame( CheckResult::UNKNOWN, $result->status );
		$this->assertSame( 'versionwatch-note-github-rate-limit', $result->note );
	}

	public function testForbiddenWithoutRateLimitIsAFailedRequest() {
		$forbidden = [ 'code' => 403, 'headers' => [ 'x-ratelimit-remaining' => '42' ] ];
		$result = $this->check( [ self::HEAD_URL => $forbidden, self::COMPARE_URL => $forbidden ] );

		$this->assertSame( 'versionwatch-note-request-failed', $result->note );
		$this->assertSame( [ 'github.com', 'HTTP 403' ], $result->noteParams );
	}

	public function testMissingBranch() {
		$result = $this->check( [
			self::HEAD_URL => [ 'code' => 422 ],
			self::COMPARE_URL => [ 'code' => 404 ],
		] );

		$this->assertSame( 'versionwatch-note-no-branch', $result->note );
	}

	public function testMissingRepository() {
		$result = $this->check( [
			self::HEAD_URL => [ 'code' => 404 ],
			self::COMPARE_URL => [ 'code' => 404 ],
		] );

		$this->assertSame( 'versionwatch-note-repo-not-found', $result->note );
		$this->assertSame( [ 'example/GTag', 'github.com' ], $result->noteParams );
	}

	public function testSecurityFixInCommitMessage() {
		$result = $this->check( [
			self::HEAD_URL => self::json( self::HEAD_RESPONSE ),
			self::COMPARE_URL => self::json( [
				'ahead_by' => 1,
				'commits' => [ [
					'sha' => sha1( 'fix' ),
					'commit' => [
						'message' => "SECURITY: Escape the tracking ID\n\nDetails",
						'author' => [ 'name' => 'Owner' ],
					],
				] ],
			] ),
		] );

		$this->assertSame( CheckResult::SECURITY, $result->status );
		$this->assertSame( 'https://github.com/example/GTag/commit/' . sha1( 'fix' ), $result->changes[0]['url'] );
		$this->assertSame(
			'https://github.com/example/GTag/compare/' . self::LOCAL_COMMIT . '...HEAD',
			$result->changesUrl
		);
	}

	public function testDownloadIsZipOfBranch() {
		$result = $this->check( [
			self::HEAD_URL => self::json( self::HEAD_RESPONSE ),
			self::COMPARE_URL => self::json( [ 'ahead_by' => 3, 'commits' => [] ] ),
		] );

		$this->assertSame( 'https://github.com/example/GTag/archive/HEAD.zip', $result->downloadUrl );
	}
}
