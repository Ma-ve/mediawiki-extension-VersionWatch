<?php

namespace MediaWiki\Extension\VersionWatch;

use MediaWiki\Extension\VersionWatch\Notifications\UpdateNotifier;

/**
 * Runs a check and keeps its report. This is what the special page and the maintenance
 * script do, so anything that should happen after every check belongs here.
 */
class CheckRunner {

	/**
	 * @param VersionChecker $versionChecker
	 * @param ReportStore $reportStore
	 * @param UpdateNotifier|null $updateNotifier Null when Echo is not installed
	 */
	public function __construct(
		private readonly VersionChecker $versionChecker,
		private readonly ReportStore $reportStore,
		private readonly ?UpdateNotifier $updateNotifier = null,
	) {
	}

	public function run(): Report {
		$previous = $this->reportStore->get();
		$report = $this->versionChecker->run();
		$this->reportStore->save( $report );
		$this->updateNotifier?->notify( $previous, $report );
		return $report;
	}
}
