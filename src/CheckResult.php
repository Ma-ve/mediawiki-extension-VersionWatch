<?php

namespace MediaWiki\Extension\VersionWatch;

/**
 * The outcome of checking one component against its upstream.
 */
class CheckResult {

	public const CURRENT = 'current';
	public const OUTDATED = 'outdated';
	public const UNSUPPORTED = 'unsupported';
	public const UNKNOWN = 'unknown';
	public const SKIPPED = 'skipped';

	/** Statuses ordered from most to least urgent. */
	public const SEVERITY_ORDER = [
		self::UNSUPPORTED,
		self::OUTDATED,
		self::UNKNOWN,
		self::CURRENT,
		self::SKIPPED,
	];

	/**
	 * @param Component $component
	 * @param string $status One of the class constants
	 * @param string|null $latest Latest version, if upstream declares one
	 * @param string|null $latestDate Release date, or date of $latestCommit, in TS_MW format
	 * @param string|null $eol End of support date (Y-m-d) of the installed release branch
	 * @param string|null $note Message key explaining the status
	 * @param array $noteParams Scalar parameters for $note
	 * @param string|null $latestCommit Head of the branch an extension or skin is compared with
	 * @param array<int,array{sha:string,subject:string,url:string}> $changes Newer upstream
	 *  changes that count as updates, newest first; possibly not all of them
	 * @param string|null $changesUrl Where to see all newer upstream changes
	 */
	public function __construct(
		public readonly Component $component,
		public readonly string $status,
		public readonly ?string $latest = null,
		public readonly ?string $latestDate = null,
		public readonly ?string $eol = null,
		public readonly ?string $note = null,
		public readonly array $noteParams = [],
		public readonly ?string $latestCommit = null,
		public readonly array $changes = [],
		public readonly ?string $changesUrl = null,
	) {
	}

	public static function unknown( Component $component, string $note, array $noteParams = [] ): self {
		return new self( component: $component, status: self::UNKNOWN, note: $note, noteParams: $noteParams );
	}

	public function needsAttention(): bool {
		return $this->status === self::OUTDATED || $this->status === self::UNSUPPORTED;
	}

	public function toArray(): array {
		return [
			'component' => $this->component->toArray(),
			'status' => $this->status,
			'latest' => $this->latest,
			'latestDate' => $this->latestDate,
			'eol' => $this->eol,
			'note' => $this->note,
			'noteParams' => $this->noteParams,
			'latestCommit' => $this->latestCommit,
			'changes' => $this->changes,
			'changesUrl' => $this->changesUrl,
		];
	}

	public static function newFromArray( array $data ): self {
		return new self(
			Component::newFromArray( $data['component'] ),
			$data['status'],
			$data['latest'],
			$data['latestDate'],
			$data['eol'],
			$data['note'],
			$data['noteParams'],
			// Not present in reports stored by earlier versions
			$data['latestCommit'] ?? null,
			$data['changes'] ?? [],
			$data['changesUrl'] ?? null,
		);
	}
}
