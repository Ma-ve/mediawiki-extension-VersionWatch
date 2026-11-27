<?php

namespace MediaWiki\Extension\VersionWatch\Upgrade;

/**
 * Whether this wiki is ready for the newest MediaWiki release.
 */
class UpgradeReport {

	/**
	 * @param string $version Newest MediaWiki release, such as "1.46.3"
	 * @param string|null $eol End of support date (Y-m-d) of its release branch
	 * @param bool $lts Whether it is a long-term support release
	 * @param string|null $phpRequirement PHP versions it supports, such as ">=8.3.0"
	 * @param bool $phpSupported Whether this server's PHP meets $phpRequirement
	 * @param UpgradeResult[] $results One per extension and skin
	 */
	public function __construct(
		public readonly string $version,
		public readonly ?string $eol,
		public readonly bool $lts,
		public readonly ?string $phpRequirement,
		public readonly bool $phpSupported,
		public readonly array $results,
	) {
	}

	/**
	 * @return UpgradeResult[] Results ordered by severity, then by component name
	 */
	public function getSortedResults(): array {
		$rank = array_flip( UpgradeResult::SEVERITY_ORDER );
		$results = $this->results;
		usort( $results, static fn ( UpgradeResult $a, UpgradeResult $b ) =>
			[ $rank[$a->status], strtolower( $a->component->name ) ]
			<=> [ $rank[$b->status], strtolower( $b->component->name ) ]
		);
		return $results;
	}

	public function toArray(): array {
		return [
			'version' => $this->version,
			'eol' => $this->eol,
			'lts' => $this->lts,
			'phpRequirement' => $this->phpRequirement,
			'phpSupported' => $this->phpSupported,
			'results' => array_map( static fn ( UpgradeResult $r ) => $r->toArray(), $this->results ),
		];
	}

	public static function newFromArray( array $data ): self {
		return new self(
			$data['version'],
			$data['eol'],
			$data['lts'],
			$data['phpRequirement'],
			$data['phpSupported'],
			array_map( [ UpgradeResult::class, 'newFromArray' ], $data['results'] ),
		);
	}
}
