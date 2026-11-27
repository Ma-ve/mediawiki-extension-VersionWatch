<?php

namespace MediaWiki\Extension\VersionWatch\Source;

use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\Requirements;
use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Repositories on GitHub, for extensions that are not hosted by Wikimedia.
 */
class GitHubSource extends GitSource {

	private const API_URL = 'https://api.github.com/repos/';
	private const RAW_URL = 'https://raw.githubusercontent.com/';

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param string[] $ignoredAuthors
	 * @param string|null $token Personal access token, to raise the rate limit
	 * @param Requirements|null $requirements
	 */
	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		array $ignoredAuthors,
		private readonly ?string $token,
		?Requirements $requirements = null,
	) {
		parent::__construct( $httpRequestFactory, $ignoredAuthors, $requirements );
	}

	protected function getDownloadUrl( Component $component, Repository $repository ): ?string {
		return "https://github.com/{$repository->path}/archive/" . rawurlencode( $repository->branch ) . '.zip';
	}

	protected function getHostName(): string {
		return 'github.com';
	}

	protected function headRequest( Repository $repository ): array {
		return $this->apiRequest( "{$repository->path}/commits/" . rawurlencode( $repository->branch ) );
	}

	protected function parseHead( Repository $repository, array $response ): array {
		$this->assertWithinRateLimit( $response );
		switch ( $response['code'] ) {
			case 200:
				$data = $this->decodeJson( $response );
				$date = $data['commit']['committer']['date'] ?? null;
				return [
					'sha' => $data['sha'],
					'date' => $date ? ( ConvertibleTimestamp::convert( TS_MW, $date ) ?: null ) : null,
				];
			case 404:
				throw new LookupFailure( 'versionwatch-note-repo-not-found', [ $repository->path, 'github.com' ] );
			// GitHub resolves the branch name as a commit, and answers 422 if it cannot.
			case 422:
				throw new LookupFailure( 'versionwatch-note-no-branch', [ $repository->branch ] );
			default:
				throw $this->unexpectedResponse( $response );
		}
	}

	protected function compareRequest( Repository $repository, string $commit ): array {
		return $this->apiRequest(
			"{$repository->path}/compare/$commit..." . rawurlencode( $repository->branch )
		);
	}

	protected function parseCompare( Repository $repository, string $commit, array $response ): array {
		$this->assertWithinRateLimit( $response );
		if ( $response['code'] === 404 ) {
			throw new LookupFailure( 'versionwatch-note-commit-not-found', [ substr( $commit, 0, 7 ) ] );
		}
		if ( $response['code'] !== 200 ) {
			throw $this->unexpectedResponse( $response );
		}
		$data = $this->decodeJson( $response );
		// "ahead_by" counts all commits, but the list is capped at 250 entries, oldest first.
		return [
			'total' => (int)( $data['ahead_by'] ?? 0 ),
			// The comparison lists the files of all commits together, not per commit.
			'commits' => array_map(
				static fn ( array $entry ) => [
					'sha' => $entry['sha'],
					'subject' => explode( "\n", $entry['commit']['message'] ?? '', 2 )[0],
					'author' => $entry['commit']['author']['name'] ?? '',
					'files' => null,
				],
				array_reverse( $data['commits'] ?? [] )
			),
		];
	}

	protected function manifestRequest( Repository $repository, string $file ): array {
		return [
			'method' => 'GET',
			'url' => self::RAW_URL . "{$repository->path}/" . rawurlencode( $repository->branch ) . "/$file",
		];
	}

	protected function parseManifest( array $response ): array {
		if ( $response['code'] === 404 ) {
			throw new LookupFailure( 'versionwatch-note-no-upstream-version' );
		}
		if ( $response['code'] !== 200 ) {
			throw $this->unexpectedResponse( $response );
		}
		return $this->decodeJson( $response );
	}

	private function apiRequest( string $path ): array {
		$headers = [
			'Accept' => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		if ( $this->token ) {
			$headers['Authorization'] = "Bearer {$this->token}";
		}
		return [
			'method' => 'GET',
			'url' => self::API_URL . $path,
			'headers' => $headers,
		];
	}

	/**
	 * @throws LookupFailure
	 */
	private function assertWithinRateLimit( array $response ): void {
		if ( in_array( $response['code'], [ 403, 429 ], true )
			&& ( $response['headers']['x-ratelimit-remaining'] ?? null ) === '0'
		) {
			throw new LookupFailure( 'versionwatch-note-github-rate-limit' );
		}
	}
}
