<?php

namespace MediaWiki\Extension\VersionWatch;

/**
 * The results of one full check run.
 */
class Report {

	/**
	 * @param string $timestamp When the check ran, in TS_MW format
	 * @param CheckResult[] $results
	 */
	public function __construct(
		public readonly string $timestamp,
		public readonly array $results,
	) {
	}

	/**
	 * @return CheckResult[] Results ordered by severity, then by component name
	 */
	public function getSortedResults(): array {
		$rank = array_flip( CheckResult::SEVERITY_ORDER );
		$results = $this->results;
		usort( $results, static fn ( CheckResult $a, CheckResult $b ) =>
			[ $rank[$a->status], strtolower( $a->component->name ) ]
			<=> [ $rank[$b->status], strtolower( $b->component->name ) ]
		);
		return $results;
	}

	public function toArray(): array {
		return [
			'timestamp' => $this->timestamp,
			'results' => array_map( static fn ( CheckResult $r ) => $r->toArray(), $this->results ),
		];
	}

	public static function newFromArray( array $data ): self {
		return new self(
			$data['timestamp'],
			array_map( [ CheckResult::class, 'newFromArray' ], $data['results'] ),
		);
	}
}
