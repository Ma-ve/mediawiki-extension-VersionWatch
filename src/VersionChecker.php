<?php

namespace MediaWiki\Extension\VersionWatch;

use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Source\ReleaseCycleSource;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Checks everything that is installed against its upstream and produces a report.
 */
class VersionChecker {

	public function __construct(
		private readonly InstalledComponents $installedComponents,
		private readonly RepositorySettings $repositorySettings,
		private readonly ReleaseCycleSource $releaseCycleSource,
		private readonly GerritSource $gerritSource,
		private readonly GitHubSource $gitHubSource,
	) {
	}

	public function run(): Report {
		$platform = [];
		$gerritTargets = [];
		$gitHubTargets = [];
		$notChecked = [];
		$modules = [];
		$coreVersion = null;
		$resolver = $this->repositorySettings->getResolver();

		foreach ( $this->installedComponents->get() as $component ) {
			if ( $component->type === Component::CORE ) {
				$coreVersion = $component->version;
			}
			if ( $component->isPlatform() ) {
				$platform[] = $component;
				continue;
			}
			if ( $component->partOf !== null ) {
				$modules[] = $component;
				continue;
			}
			try {
				$repository = $resolver->resolve( $component );
			} catch ( InvalidSettingException ) {
				$notChecked[] = CheckResult::unknown( $component, 'versionwatch-note-invalid-setting' );
				continue;
			}
			if ( $repository === null ) {
				$notChecked[] = new CheckResult( $component, CheckResult::SKIPPED );
			} elseif ( $repository->host === Repository::GITHUB ) {
				$gitHubTargets[] = [ $component, $repository ];
			} else {
				$gerritTargets[] = [ $component, $repository ];
			}
		}

		if ( $coreVersion !== null ) {
			$gerritTargets = $this->withBundledCommits( $gerritTargets, $coreVersion );
		}

		$results = array_merge(
			$this->releaseCycleSource->check( $platform ),
			$this->gerritSource->check( $gerritTargets ),
			$this->gitHubSource->check( $gitHubTargets ),
			$notChecked
		);
		return new Report(
			ConvertibleTimestamp::now( TS_MW ),
			array_merge( $results, $this->followParents( $modules, $results ) )
		);
	}

	/**
	 * A module inside another extension, such as ConfirmEdit/Turnstile, has no repository
	 * of its own and is updated together with that extension, so it gets its status.
	 *
	 * @param Component[] $modules
	 * @param CheckResult[] $results
	 * @return CheckResult[]
	 */
	private function followParents( array $modules, array $results ): array {
		$byName = [];
		foreach ( $results as $result ) {
			$byName[$result->component->type][$result->component->name] = $result;
		}

		return array_map( static function ( Component $module ) use ( $byName ) {
			$parent = $byName[$module->type][$module->partOf] ?? null;
			return new CheckResult(
				$module,
				$parent?->status ?? CheckResult::UNKNOWN,
				$parent?->latest,
				$parent?->latestDate,
				null,
				'versionwatch-note-part-of',
				[ $module->partOf ]
			);
		}, $modules );
	}

	/**
	 * Extensions and skins that came with the MediaWiki download have no git information,
	 * but the release pins the commit of each, so compare that commit instead.
	 *
	 * @param array<int,array{0:Component,1:Repository}> $targets
	 * @param string $mwVersion
	 * @return array<int,array{0:Component,1:Repository}>
	 */
	private function withBundledCommits( array $targets, string $mwVersion ): array {
		$withoutCommit = array_filter( $targets, static fn ( array $target ) => $target[0]->commit === null );
		if ( !$withoutCommit ) {
			return $targets;
		}

		$bundled = $this->gerritSource->getBundledCommits( $mwVersion );
		foreach ( $withoutCommit as $i => [ $component, $repository ] ) {
			$commit = $bundled[$component->type][$component->name] ?? null;
			if ( $commit !== null ) {
				$targets[$i] = [ $component->withBundledCommit( $commit, $mwVersion ), $repository ];
			}
		}
		return $targets;
	}
}
