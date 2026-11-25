<?php

namespace MediaWiki\Extension\VersionWatch;

use Wikimedia\ObjectCache\BagOStuff;

/**
 * Keeps the most recent report in the main object stash, so viewing the special page
 * never waits on remote requests.
 */
class ReportStore {

	/**
	 * Bumped whenever the stored array format changes, so old entries are ignored.
	 */
	private const SCHEMA_VERSION = 1;

	public function __construct(
		private readonly BagOStuff $stash,
	) {
	}

	public function get(): ?Report {
		$data = $this->stash->get( $this->makeKey() );
		return is_array( $data ) ? Report::newFromArray( $data ) : null;
	}

	public function save( Report $report ): void {
		$this->stash->set( $this->makeKey(), $report->toArray(), BagOStuff::TTL_MONTH );
	}

	private function makeKey(): string {
		return $this->stash->makeKey( 'versionwatch-report', self::SCHEMA_VERSION );
	}
}
