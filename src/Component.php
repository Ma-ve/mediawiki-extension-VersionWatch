<?php

namespace MediaWiki\Extension\VersionWatch;

/**
 * A piece of installed software: MediaWiki itself, the runtime, the database server,
 * or an extension or skin.
 */
class Component {

	public const CORE = 'core';
	public const PHP = 'php';
	public const DATABASE = 'database';
	public const EXTENSION = 'extension';
	public const SKIN = 'skin';

	/**
	 * @param string $type One of the class constants
	 * @param string $name Product name for platform components, directory name for
	 *  extensions and skins (the name passed to wfLoadExtension())
	 * @param string|null $version Installed version, if the software declares one
	 * @param string|null $commit Full SHA-1 of the checked out commit, if installed from git
	 * @param string|null $commitDate Commit date in TS_MW format
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $name,
		public readonly ?string $version = null,
		public readonly ?string $commit = null,
		public readonly ?string $commitDate = null,
	) {
	}

	public function isPlatform(): bool {
		return in_array( $this->type, [ self::CORE, self::PHP, self::DATABASE ], true );
	}

	/**
	 * The manifest file an extension or skin is registered with.
	 */
	public function getManifestFile(): string {
		return $this->type === self::SKIN ? 'skin.json' : 'extension.json';
	}

	public function toArray(): array {
		return [
			'type' => $this->type,
			'name' => $this->name,
			'version' => $this->version,
			'commit' => $this->commit,
			'commitDate' => $this->commitDate,
		];
	}

	public static function newFromArray( array $data ): self {
		return new self(
			$data['type'],
			$data['name'],
			$data['version'],
			$data['commit'],
			$data['commitDate'],
		);
	}
}
