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

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param string[] $ignoredAuthors Authors whose commits do not make an extension outdated
	 */
	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
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
	 * @return array{total:int,authors:string[]} Number of commits on the branch that are not
	 *  in $commit, and the authors of those commits (possibly not all of them)
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
				$requests["$i-detail"] = $this->compareRequest( $repository, $component->commit );
			} elseif ( $component->version !== null ) {
				$requests["$i-detail"] = $this->manifestRequest( $repository, $component->getManifestFile() );
			}
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
					$responses["$i-detail"]['response'] ?? null
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
		?array $detailResponse
	): CheckResult {
		// Parse the head first: a missing repository or branch explains everything else.
		$head = $this->parseHead( $repository, $headResponse );

		// There is no second request only if there is neither a commit nor a version to compare.
		if ( $detailResponse === null ) {
			throw new LookupFailure( 'versionwatch-note-no-version' );
		}
		if ( $component->commit !== null ) {
			$behind = $this->parseCompare( $repository, $component->commit, $detailResponse );
			return $this->evaluateCommits( $component, $repository, $head, $behind );
		}
		return $this->evaluateVersion( $component, $this->parseManifest( $detailResponse ) );
	}

	private function evaluateCommits(
		Component $component,
		Repository $repository,
		array $head,
		array $behind
	): CheckResult {
		$ignored = count( array_intersect( $behind['authors'], $this->ignoredAuthors ) );
		$relevant = $behind['total'] - $ignored;

		if ( $relevant > 0 ) {
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
			$component,
			$status,
			substr( $head['sha'], 0, 7 ),
			$head['date'],
			null,
			$note,
			$params
		);
	}

	private function evaluateVersion( Component $component, array $manifest ): CheckResult {
		$latest = $manifest['version'] ?? null;
		if ( !is_string( $latest ) ) {
			throw new LookupFailure( 'versionwatch-note-no-upstream-version' );
		}
		$status = version_compare( (string)$component->version, $latest, '<' )
			? CheckResult::OUTDATED
			: CheckResult::CURRENT;
		return new CheckResult( $component, $status, $latest );
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
