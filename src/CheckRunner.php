<?php

namespace MediaWiki\Extension\VersionWatch;

/**
 * Runs a check and keeps its report. This is what the special page and the maintenance
 * script do, so anything that should happen after every check belongs here.
 */
class CheckRunner {

	public function __construct(
		private readonly VersionChecker $versionChecker,
		private readonly ReportStore $reportStore,
	) {
	}

	public function run(): Report {
		$report = $this->versionChecker->run();
		$this->reportStore->save( $report );
		return $report;
	}
}
