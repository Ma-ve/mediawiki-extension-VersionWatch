<?php

namespace MediaWiki\Extension\VersionWatch\Upgrade;

use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\RepositoryResolver;
use MediaWiki\Extension\VersionWatch\Requirements;
use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Source\LookupFailure;

/**
 * Works out whether the installed extensions and skins can be used with a newer MediaWiki
 * release, by looking at the branch each would be updated from.
 */
class UpgradeChecker {

	public function __construct(
		private readonly GerritSource $gerritSource,
		private readonly GitHubSource $gitHubSource,
		private readonly Requirements $requirements,
	) {
	}

	/**
	 * @param array{latest:string,eol:?string,lts:bool} $release Newest MediaWiki release
	 * @param string $installedBranch Release branch of the installed MediaWiki, such as "REL1_43"
	 * @param array<int,array{0:Component,1:Repository}> $targets Extensions and skins with a repository
	 * @param Component[] $skipped Extensions and skins that are not checked
	 * @param Component[] $modules Modules inside another extension
	 */
	public function check(
		array $release,
		string $installedBranch,
		array $targets,
		array $skipped,
		array $modules
	): UpgradeReport {
		$version = $release['latest'];
		$targetBranch = RepositoryResolver::releaseBranchFor( $version );
		$bundled = $this->gerritSource->getBundledCommits( $version );

		$results = [];
		$toFetch = [ Repository::GERRIT => [], Repository::GITHUB => [] ];
		foreach ( $targets as [ $component, $repository ] ) {
			if ( isset( $bundled[$component->type][$component->name] ) ) {
				$results[] = new UpgradeResult(
					$component, UpgradeResult::BUNDLED, 'versionwatch-upgrade-note-bundled', [ $version ]
				);
				continue;
			}
			// Follow the release branches along; anything else, such as master, stays as set.
			if ( $repository->branch === $installedBranch ) {
				$repository = new Repository( $repository->host, $repository->path, $targetBranch );
			}
			$toFetch[$repository->host][] = [ $component, $repository ];
		}

		foreach ( [
			Repository::GERRIT => $this->gerritSource,
			Repository::GITHUB => $this->gitHubSource,
		] as $host => $source ) {
			$manifests = $source->getManifests( $toFetch[$host] );
			foreach ( $toFetch[$host] as $i => [ $component, $repository ] ) {
				$results[] = $this->evaluate(
					$component, $repository, $manifests[$i] ?? null, $version, $targetBranch
				);
			}
		}

		foreach ( $skipped as $component ) {
			$results[] = new UpgradeResult( $component, UpgradeResult::SKIPPED );
		}
		$results = array_merge( $results, $this->followParents( $modules, $results ) );

		$phpRequirement = $this->gerritSource->getCorePhpRequirement( $version );
		return new UpgradeReport(
			$version,
			$release['eol'],
			$release['lts'],
			$phpRequirement,
			$phpRequirement === null || $this->requirements->isPhpSupported( $phpRequirement ),
			$results
		);
	}

	/**
	 * @param Component $component
	 * @param Repository $repository
	 * @param array|LookupFailure|null $manifest
	 * @param string $version
	 * @param string $targetBranch
	 */
	private function evaluate(
		Component $component,
		Repository $repository,
		$manifest,
		string $version,
		string $targetBranch
	): UpgradeResult {
		if ( $manifest instanceof LookupFailure ) {
			return new UpgradeResult(
				$component, UpgradeResult::UNKNOWN, $manifest->messageKey, $manifest->messageParams
			);
		}
		if ( $manifest === null ) {
			return $repository->branch === $targetBranch
				? new UpgradeResult(
					$component,
					UpgradeResult::NO_BRANCH,
					'versionwatch-upgrade-note-no-branch',
					[ $targetBranch, $version ]
				)
				: new UpgradeResult(
					$component,
					UpgradeResult::UNKNOWN,
					'versionwatch-upgrade-note-no-manifest',
					[ $component->getManifestFile(), $repository->branch ]
				);
		}

		$unmet = $this->requirements->getUnmet( $manifest['requires'] ?? null, $version );
		if ( $unmet ) {
			return new UpgradeResult( $component, UpgradeResult::INCOMPATIBLE, null, [], $unmet );
		}
		return new UpgradeResult(
			$component, UpgradeResult::READY, 'versionwatch-upgrade-note-ready', [ $repository->branch ]
		);
	}

	/**
	 * A module inside another extension is updated together with it.
	 *
	 * @param Component[] $modules
	 * @param UpgradeResult[] $results
	 * @return UpgradeResult[]
	 */
	private function followParents( array $modules, array $results ): array {
		$byName = [];
		foreach ( $results as $result ) {
			$byName[$result->component->type][$result->component->name] = $result;
		}
		return array_map( static function ( Component $module ) use ( $byName ) {
			$parent = $byName[$module->type][$module->partOf] ?? null;
			return new UpgradeResult(
				$module,
				$parent?->status ?? UpgradeResult::UNKNOWN,
				'versionwatch-note-part-of',
				[ $module->partOf ],
				$parent?->unmetRequirements ?? []
			);
		}, $modules );
	}

}
