<?php

namespace MediaWiki\Extension\VersionWatch;

use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Source\ReleaseCycleSource;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeChecker;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeReport;
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
		private readonly ?UpgradeChecker $upgradeChecker = null,
	) {
	}

	public function run(): Report {
		$platform = [];
		$gerritTargets = [];
		$gitHubTargets = [];
		$notChecked = [];
		$skipped = [];
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
				$skipped[] = $component;
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
			array_map( [ $this, 'withCoreSecurityFixes' ], $this->releaseCycleSource->check( $platform ) ),
			$this->gerritSource->check( $gerritTargets ),
			$this->gitHubSource->check( $gitHubTargets ),
			$notChecked
		);
		return new Report(
			ConvertibleTimestamp::now( TS_MW ),
			array_merge( $results, $this->followParents( $modules, $results ) ),
			$coreVersion === null ? null : $this->checkUpgrade(
				$coreVersion, array_merge( $gerritTargets, $gitHubTargets ), $skipped, $modules
			)
		);
	}

	/**
	 * Whether this wiki is ready for the newest MediaWiki release, if that is on a newer
	 * release branch than the installed one.
	 *
	 * @param string $coreVersion
	 * @param array<int,array{0:Component,1:Repository}> $targets
	 * @param Component[] $skipped
	 * @param Component[] $modules
	 */
	private function checkUpgrade(
		string $coreVersion,
		array $targets,
		array $skipped,
		array $modules
	): ?UpgradeReport {
		$release = $this->upgradeChecker ? $this->releaseCycleSource->getNewestMediaWikiRelease() : null;
		$installedBranch = RepositoryResolver::releaseBranchFor( $coreVersion );
		if ( $release === null || RepositoryResolver::releaseBranchFor( $release['latest'] ) === $installedBranch
			|| version_compare( $release['latest'], $coreVersion, '<' )
		) {
			return null;
		}
		return $this->upgradeChecker->check( $release, $installedBranch, $targets, $skipped, $modules );
	}

	/**
	 * A newer MediaWiki release on the installed branch may include security fixes. Those
	 * make updating urgent, so look them up and raise the status.
	 */
	private function withCoreSecurityFixes( CheckResult $result ): CheckResult {
		$installed = $result->component->version;
		if ( $result->component->type !== Component::CORE
			|| $result->status !== CheckResult::OUTDATED
			|| $installed === null
			|| $result->latest === null
		) {
			return $result;
		}

		$fixes = $this->gerritSource->getCoreSecurityFixes( $installed, $result->latest );
		if ( !$fixes ) {
			return $result;
		}
		return new CheckResult(
			component: $result->component,
			status: CheckResult::SECURITY,
			latest: $result->latest,
			latestDate: $result->latestDate,
			eol: $result->eol,
			note: 'versionwatch-note-core-security',
			noteParams: [ $result->latest, count( $fixes ) ],
			changes: $fixes,
			changesUrl: GerritSource::getCoreChangesUrl( $installed, $result->latest ),
			downloadUrl: $result->downloadUrl
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
				component: $module,
				status: $parent?->status ?? CheckResult::UNKNOWN,
				latest: $parent?->latest,
				latestDate: $parent?->latestDate,
				note: 'versionwatch-note-part-of',
				noteParams: [ $module->partOf ],
				latestCommit: $parent?->latestCommit
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
