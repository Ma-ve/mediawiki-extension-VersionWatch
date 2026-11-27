<?php

namespace MediaWiki\Extension\VersionWatch;

/**
 * The outcome of checking one component against its upstream.
 */
class CheckResult {

	public const SECURITY = 'security';
	public const CURRENT = 'current';
	public const OUTDATED = 'outdated';
	public const UNSUPPORTED = 'unsupported';
	public const UNKNOWN = 'unknown';
	public const SKIPPED = 'skipped';

	/** Statuses ordered from most to least urgent. */
	public const SEVERITY_ORDER = [
		self::SECURITY,
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
	 * @param array<int,array{sha:string,subject:string,url:string,security:bool}> $changes Newer
	 *  upstream changes that count as updates, security fixes first, then newest first;
	 *  possibly not all of them
	 * @param string|null $changesUrl Where to see all newer upstream changes
	 * @param string|null $downloadUrl Where to get the update
	 * @param array<int,array{name:string,constraint:string,installed:string}> $unmetRequirements
	 *  What the update needs that this server does not have
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
		public readonly ?string $downloadUrl = null,
		public readonly array $unmetRequirements = [],
	) {
	}

	public static function unknown( Component $component, string $note, array $noteParams = [] ): self {
		return new self( component: $component, status: self::UNKNOWN, note: $note, noteParams: $noteParams );
	}

	public function needsAttention(): bool {
		return in_array( $this->status, [ self::SECURITY, self::UNSUPPORTED, self::OUTDATED ], true );
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
			'downloadUrl' => $this->downloadUrl,
			'unmetRequirements' => $this->unmetRequirements,
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
			$data['downloadUrl'] ?? null,
			$data['unmetRequirements'] ?? [],
		);
	}
}
