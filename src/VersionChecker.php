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
		$skipped = [];
		$resolver = $this->repositorySettings->getResolver();

		foreach ( $this->installedComponents->get() as $component ) {
			if ( $component->isPlatform() ) {
				$platform[] = $component;
				continue;
			}
			$repository = $resolver->resolve( $component );
			if ( $repository === null ) {
				$skipped[] = new CheckResult( $component, CheckResult::SKIPPED );
			} elseif ( $repository->host === Repository::GITHUB ) {
				$gitHubTargets[] = [ $component, $repository ];
			} else {
				$gerritTargets[] = [ $component, $repository ];
			}
		}

		return new Report(
			ConvertibleTimestamp::now( TS_MW ),
			array_merge(
				$this->releaseCycleSource->check( $platform ),
				$this->gerritSource->check( $gerritTargets ),
				$this->gitHubSource->check( $gitHubTargets ),
				$skipped
			)
		);
	}
}
