<?php

namespace MediaWiki\Extension\VersionWatch\Upgrade;

use MediaWiki\Extension\VersionWatch\Component;

/**
 * Whether one extension or skin can be used with a newer MediaWiki release.
 */
class UpgradeResult {

	/** A version for the release exists and its requirements are met. */
	public const READY = 'ready';
	/** It comes with the release's download. */
	public const BUNDLED = 'bundled';
	/** It has no release branch for the release, so it is not maintained for it. */
	public const NO_BRANCH = 'no-branch';
	/** The version for the release needs something this server does not have. */
	public const INCOMPATIBLE = 'incompatible';
	public const UNKNOWN = 'unknown';
	public const SKIPPED = 'skipped';

	/** Statuses ordered from most to least in need of attention. */
	public const SEVERITY_ORDER = [
		self::INCOMPATIBLE,
		self::NO_BRANCH,
		self::UNKNOWN,
		self::READY,
		self::BUNDLED,
		self::SKIPPED,
	];

	/**
	 * @param Component $component
	 * @param string $status One of the class constants
	 * @param string|null $note Message key explaining the status
	 * @param array $noteParams Scalar parameters for $note
	 * @param array<int,array{name:string,constraint:string,installed:string}> $unmetRequirements
	 */
	public function __construct(
		public readonly Component $component,
		public readonly string $status,
		public readonly ?string $note = null,
		public readonly array $noteParams = [],
		public readonly array $unmetRequirements = [],
	) {
	}

	public function needsAttention(): bool {
		return in_array( $this->status, [ self::INCOMPATIBLE, self::NO_BRANCH ], true );
	}

	public function toArray(): array {
		return [
			'component' => $this->component->toArray(),
			'status' => $this->status,
			'note' => $this->note,
			'noteParams' => $this->noteParams,
			'unmetRequirements' => $this->unmetRequirements,
		];
	}

	public static function newFromArray( array $data ): self {
		return new self(
			Component::newFromArray( $data['component'] ),
			$data['status'],
			$data['note'],
			$data['noteParams'],
			$data['unmetRequirements'],
		);
	}
}
