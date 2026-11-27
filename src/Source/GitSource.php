<?php

namespace MediaWiki\Extension\VersionWatch\Source;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Http\HttpRequestFactory;

/**
 * Checks extensions and skins against a branch in their upstream git repository.
 *
 * An extension installed from git (or from ExtensionDistributor, which records the commit)
 * is compared commit by commit. Otherwise the version in its extension.json is compared
 * with the one on the branch. Subclasses implement the hosting service's API.
 */
abstract class GitSource {

	/** Be polite to the hosting service: there can be dozens of extensions. */
	private const MAX_CONNECTIONS_PER_HOST = 4;

	/** Changes kept per extension for the report; the rest are a link away. */
	private const MAX_LISTED_CHANGES = 50;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param string[] $ignoredAuthors Authors whose commits do not make an extension outdated
	 */
	public function __construct(
		protected readonly HttpRequestFactory $httpRequestFactory,
		private readonly array $ignoredAuthors,
	) {
	}

	/**
	 * Host name shown to the administrator in messages.
	 */
	abstract protected function getHostName(): string;

	abstract protected function headRequest( Repository $repository ): array;

	/**
	 * @param Repository $repository
	 * @param array $response
	 * @return array{sha:string,date:?string} Branch head commit and its date in TS_MW format
	 * @throws LookupFailure
	 */
	abstract protected function parseHead( Repository $repository, array $response ): array;

	abstract protected function compareRequest( Repository $repository, string $commit ): array;

	/**
	 * @param Repository $repository
	 * @param string $commit
	 * @param array $response
	 * @return array{total:int,commits:array<int,array{sha:string,subject:string,author:string,files:?string[]}>}
	 *  Number of commits on the branch that are not in $commit, and those commits (possibly
	 *  not all of them), newest first, with their first message line, author and, if the
	 *  host lists them, the files they change
	 * @throws LookupFailure
	 */
	abstract protected function parseCompare( Repository $repository, string $commit, array $response ): array;

	abstract protected function manifestRequest( Repository $repository, string $file ): array;

	/**
	 * @param array $response
	 * @return array Decoded extension.json or skin.json
	 * @throws LookupFailure
	 */
	abstract protected function parseManifest( array $response ): array;

	/**
	 * @param array<int,array{0:Component,1:Repository}> $targets
	 * @return CheckResult[]
	 */
	public function check( array $targets ): array {
		if ( !$targets ) {
			return [];
		}

		$requests = [];
		foreach ( $targets as $i => [ $component, $repository ] ) {
			$requests["$i-head"] = $this->headRequest( $repository );
			if ( $component->commit !== null ) {
				$requests["$i-compare"] = $this->compareRequest( $repository, $component->commit );
			}
			// Also for git checkouts, to show which version the branch is at.
			$requests["$i-manifest"] = $this->manifestRequest( $repository, $component->getManifestFile() );
		}
		$responses = $this->httpRequestFactory->createMultiClient()
			->runMulti( $requests, [ 'maxConnsPerHost' => self::MAX_CONNECTIONS_PER_HOST ] );

		$results = [];
		foreach ( $targets as $i => [ $component, $repository ] ) {
			try {
				$results[] = $this->evaluate(
					$component,
					$repository,
					$responses["$i-head"]['response'],
					$responses["$i-compare"]['response'] ?? null,
					$responses["$i-manifest"]['response'] ?? null
				);
			} catch ( LookupFailure $e ) {
				$results[] = CheckResult::unknown( $component, $e->messageKey, $e->messageParams );
			}
		}
		return $results;
	}

	private function evaluate(
		Component $component,
		Repository $repository,
		array $headResponse,
		?array $compareResponse,
		?array $manifestResponse
	): CheckResult {
		// Parse the head first: a missing repository or branch explains everything else.
		$head = $this->parseHead( $repository, $headResponse );

		if ( $component->commit !== null && $compareResponse !== null ) {
			$behind = $this->parseCompare( $repository, $component->commit, $compareResponse );
			return $this->evaluateCommits(
				$component, $repository, $head, $behind, $this->getUpstreamVersion( $manifestResponse )
			);
		}
		if ( $component->version !== null && $manifestResponse !== null ) {
			return $this->evaluateVersion( $component, $head, $this->parseManifest( $manifestResponse ) );
		}
		throw new LookupFailure( 'versionwatch-note-no-version' );
	}

	/**
	 * The version on the branch, for display only: a git checkout is compared by commit,
	 * and many extensions do not declare a version.
	 */
	private function getUpstreamVersion( ?array $manifestResponse ): ?string {
		if ( $manifestResponse === null ) {
			return null;
		}
		try {
			$version = $this->parseManifest( $manifestResponse )['version'] ?? null;
		} catch ( LookupFailure ) {
			return null;
		}
		return is_string( $version ) ? $version : null;
	}

	private function evaluateCommits(
		Component $component,
		Repository $repository,
		array $head,
		array $behind,
		?string $upstreamVersion
	): CheckResult {
		$counted = array_values( array_filter(
			$behind['commits'],
			fn ( array $commit ) => !$this->isIgnored( $commit )
		) );
		// Commits the host did not list are counted, since nothing is known about them.
		$relevant = $behind['total'] - ( count( $behind['commits'] ) - count( $counted ) );
		$changes = array_map( static fn ( array $commit ) => [
			'sha' => $commit['sha'],
			'subject' => $commit['subject'],
			'url' => $repository->getCommitUrl( $commit['sha'] ),
			'security' => SecurityFixes::isSecurityFix( $commit['subject'] ),
		], $counted );
		// Stable sort, so both groups stay newest first.
		usort( $changes, static fn ( array $a, array $b ) => $b['security'] <=> $a['security'] );
		$securityFixes = count( array_filter( array_column( $changes, 'security' ) ) );
		$changes = array_slice( $changes, 0, self::MAX_LISTED_CHANGES );

		if ( $securityFixes > 0 ) {
			$status = CheckResult::SECURITY;
			$note = 'versionwatch-note-security-changes';
			$params = [ $relevant, $repository->branch, $securityFixes ];
		} elseif ( $relevant > 0 ) {
			$status = CheckResult::OUTDATED;
			$note = 'versionwatch-note-commits-behind';
			$params = [ $relevant, $repository->branch ];
		} elseif ( $behind['total'] > 0 ) {
			$status = CheckResult::CURRENT;
			$note = 'versionwatch-note-only-ignored-commits';
			$params = [ $behind['total'] ];
		} else {
			$status = CheckResult::CURRENT;
			$note = null;
			$params = [];
		}

		return new CheckResult(
			component: $component,
			status: $status,
			latest: $upstreamVersion,
			latestDate: $head['date'],
			note: $note,
			noteParams: $params,
			latestCommit: $head['sha'],
			changes: $changes,
			changesUrl: $relevant > 0 ? $repository->getChangesUrl( (string)$component->commit ) : null
		);
	}

	/**
	 * Whether a commit does not make an extension outdated: it is by an ignored author, such
	 * as the translation bot, or only changes development files.
	 *
	 * @param array{author:string,files:?string[]} $commit
	 */
	private function isIgnored( array $commit ): bool {
		return in_array( $commit['author'], $this->ignoredAuthors, true )
			|| ( $commit['files'] !== null && DevelopmentFiles::containsAll( $commit['files'] ) );
	}

	private function evaluateVersion( Component $component, array $head, array $manifest ): CheckResult {
		$latest = $manifest['version'] ?? null;
		if ( !is_string( $latest ) ) {
			throw new LookupFailure( 'versionwatch-note-no-upstream-version' );
		}
		$status = version_compare( (string)$component->version, $latest, '<' )
			? CheckResult::OUTDATED
			: CheckResult::CURRENT;
		return new CheckResult(
			component: $component,
			status: $status,
			latest: $latest,
			latestDate: $head['date'],
			latestCommit: $head['sha']
		);
	}

	/**
	 * @param array $response
	 * @return array
	 * @throws LookupFailure
	 */
	protected function decodeJson( array $response ): array {
		$data = json_decode( $response['body'], true );
		if ( !is_array( $data ) ) {
			throw new LookupFailure( 'versionwatch-note-bad-response', [ $this->getHostName() ] );
		}
		return $data;
	}

	protected function unexpectedResponse( array $response ): LookupFailure {
		return LookupFailure::forResponse( $this->getHostName(), $response );
	}
}
