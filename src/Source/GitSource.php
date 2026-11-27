<?php

namespace MediaWiki\Extension\VersionWatch\Source;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\Requirements;
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
	 * @param Requirements|null $requirements To warn when an update needs a newer PHP or
	 *  MediaWiki; null to not check
	 */
	public function __construct(
		protected readonly HttpRequestFactory $httpRequestFactory,
		private readonly array $ignoredAuthors,
		private readonly ?Requirements $requirements = null,
	) {
	}

	/**
	 * Host name shown to the administrator in messages.
	 */
	abstract protected function getHostName(): string;

	/**
	 * Where to download the code on the branch, if the host offers that.
	 */
	abstract protected function getDownloadUrl( Component $component, Repository $repository ): ?string;

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

	/**
	 * Fetch the extension.json or skin.json of each target's branch.
	 *
	 * @param array<int,array{0:Component,1:Repository}> $targets
	 * @return array<int,array|LookupFailure|null> The manifest, why it could not be fetched, or
	 *  null if the branch or the file does not exist
	 */
	public function getManifests( array $targets ): array {
		$requests = [];
		foreach ( $targets as $i => [ $component, $repository ] ) {
			$requests[$i] = $this->manifestRequest( $repository, $component->getManifestFile() );
		}
		if ( !$requests ) {
			return [];
		}
		$responses = $this->httpRequestFactory->createMultiClient()
			->runMulti( $requests, [ 'maxConnsPerHost' => self::MAX_CONNECTIONS_PER_HOST ] );

		$manifests = [];
		foreach ( $responses as $i => $request ) {
			if ( $request['response']['code'] === 404 ) {
				$manifests[$i] = null;
				continue;
			}
			try {
				$manifests[$i] = $this->parseManifest( $request['response'] );
			} catch ( LookupFailure $e ) {
				$manifests[$i] = $e;
			}
		}
		return $manifests;
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
				$component, $repository, $head, $behind, $this->getUpstreamManifest( $manifestResponse )
			);
		}
		if ( $component->version !== null && $manifestResponse !== null ) {
			return $this->evaluateVersion( $component, $repository, $head, $this->parseManifest( $manifestResponse ) );
		}
		throw new LookupFailure( 'versionwatch-note-no-version' );
	}

	/**
	 * The manifest on the branch of a git checkout. It is only informational, since a
	 * checkout is compared by commit, so it may be missing.
	 */
	private function getUpstreamManifest( ?array $manifestResponse ): array {
		if ( $manifestResponse === null ) {
			return [];
		}
		try {
			return $this->parseManifest( $manifestResponse );
		} catch ( LookupFailure ) {
			return [];
		}
	}

	private function evaluateCommits(
		Component $component,
		Repository $repository,
		array $head,
		array $behind,
		array $manifest
	): CheckResult {
		$upstreamVersion = is_string( $manifest['version'] ?? null ) ? $manifest['version'] : null;
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

		// "HEAD" means nothing to a reader, so the default branch gets its own wording.
		$default = $repository->isDefaultBranch() ? '-default' : '';
		if ( $securityFixes > 0 ) {
			$status = CheckResult::SECURITY;
			// Messages used: versionwatch-note-security-changes,
			// versionwatch-note-security-changes-default
			$note = "versionwatch-note-security-changes$default";
			$params = [ $relevant, $repository->branch, $securityFixes ];
		} elseif ( $relevant > 0 ) {
			$status = CheckResult::OUTDATED;
			// Messages used: versionwatch-note-commits-behind, versionwatch-note-commits-behind-default
			$note = "versionwatch-note-commits-behind$default";
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
			changesUrl: $relevant > 0 ? $repository->getChangesUrl( (string)$component->commit ) : null,
			downloadUrl: $relevant > 0 ? $this->getDownloadUrl( $component, $repository ) : null,
			unmetRequirements: $relevant > 0 ? $this->getUnmetRequirements( $manifest ) : []
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

	private function evaluateVersion(
		Component $component,
		Repository $repository,
		array $head,
		array $manifest
	): CheckResult {
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
			latestCommit: $head['sha'],
			downloadUrl: $status === CheckResult::OUTDATED ? $this->getDownloadUrl( $component, $repository ) : null,
			unmetRequirements: $status === CheckResult::OUTDATED ? $this->getUnmetRequirements( $manifest ) : []
		);
	}

	/**
	 * @param array $manifest Upstream extension.json or skin.json
	 * @return array<int,array{name:string,constraint:string,installed:string}>
	 */
	private function getUnmetRequirements( array $manifest ): array {
		return $this->requirements?->getUnmet( $manifest['requires'] ?? null ) ?? [];
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
