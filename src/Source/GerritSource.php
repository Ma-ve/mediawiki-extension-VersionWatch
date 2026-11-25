<?php

namespace MediaWiki\Extension\VersionWatch\Source;

use DateTimeImmutable;
use MediaWiki\Extension\VersionWatch\Repository;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Wikimedia Gerrit, read through its Gitiles web interface, which needs no
 * authentication and has no practical rate limit.
 */
class GerritSource extends GitSource {

	private const HOST = 'gerrit.wikimedia.org';
	private const BASE_URL = 'https://' . self::HOST . '/r/plugins/gitiles/';

	/** Gitiles prefixes JSON responses with this to prevent cross-site script inclusion. */
	private const XSSI_PREFIX = ")]}'";

	/** Enough to list everything a checkout is behind in one request, even years-old ones. */
	private const LOG_LIMIT = 10000;

	protected function getHostName(): string {
		return self::HOST;
	}

	protected function headRequest( Repository $repository ): array {
		return $this->request( $repository, "+/refs/heads/{$repository->branch}?format=JSON" );
	}

	protected function parseHead( Repository $repository, array $response ): array {
		switch ( $response['code'] ) {
			case 200:
				$data = $this->decodeJson( $response );
				return [
					'sha' => $data['commit'],
					'date' => self::parseGitDate( $data['committer']['time'] ?? '' ),
				];
			case 404:
				throw new LookupFailure( 'versionwatch-note-no-branch', [ $repository->branch ] );
			// Gerrit answers 401 rather than 404 for projects that do not exist.
			case 401:
			case 403:
				throw new LookupFailure( 'versionwatch-note-repo-not-found', [ $repository->path, self::HOST ] );
			default:
				throw $this->unexpectedResponse( $response );
		}
	}

	protected function compareRequest( Repository $repository, string $commit ): array {
		return $this->request(
			$repository,
			"+log/$commit..{$repository->branch}?format=JSON&n=" . self::LOG_LIMIT
		);
	}

	protected function parseCompare( Repository $repository, string $commit, array $response ): array {
		if ( $response['code'] === 404 ) {
			throw new LookupFailure( 'versionwatch-note-commit-not-found', [ substr( $commit, 0, 7 ) ] );
		}
		if ( $response['code'] !== 200 ) {
			throw $this->unexpectedResponse( $response );
		}
		$log = $this->decodeJson( $response )['log'] ?? [];
		return [
			'total' => count( $log ),
			'authors' => array_map( static fn ( array $entry ) => $entry['author']['name'] ?? '', $log ),
		];
	}

	protected function manifestRequest( Repository $repository, string $file ): array {
		return $this->request( $repository, "+/refs/heads/{$repository->branch}/$file?format=TEXT" );
	}

	protected function parseManifest( array $response ): array {
		if ( $response['code'] === 404 ) {
			throw new LookupFailure( 'versionwatch-note-no-upstream-version' );
		}
		if ( $response['code'] !== 200 ) {
			throw $this->unexpectedResponse( $response );
		}
		// format=TEXT returns the file base64-encoded.
		return $this->decodeJson( [ 'body' => base64_decode( $response['body'] ) ] + $response );
	}

	protected function decodeJson( array $response ): array {
		$body = $response['body'];
		if ( str_starts_with( $body, self::XSSI_PREFIX ) ) {
			$body = substr( $body, strlen( self::XSSI_PREFIX ) );
		}
		return parent::decodeJson( [ 'body' => $body ] + $response );
	}

	private function request( Repository $repository, string $path ): array {
		return [
			'method' => 'GET',
			'url' => self::BASE_URL . "{$repository->path}/$path",
		];
	}

	/**
	 * Gitiles formats dates like git log does: "Tue Nov 24 05:35:35 2026 +0100".
	 */
	private static function parseGitDate( string $date ): ?string {
		$parsed = DateTimeImmutable::createFromFormat( 'D M j H:i:s Y O', $date );
		return $parsed ? ConvertibleTimestamp::convert( TS_MW, $parsed->getTimestamp() ) : null;
	}
}
