<?php

namespace MediaWiki\Extension\VersionWatch;

use Composer\Semver\Semver;
use UnexpectedValueException;

/**
 * Evaluates the "requires" section of an extension.json or skin.json against this
 * server, the same way ExtensionRegistry does when it loads an extension.
 */
class Requirements {

	/**
	 * @param string $mwVersion Installed MediaWiki version
	 * @param string $phpVersion PHP version of this server
	 */
	public function __construct(
		private readonly string $mwVersion,
		private readonly string $phpVersion,
	) {
	}

	/**
	 * @param mixed $requires The "requires" value of a manifest
	 * @param string|null $mwVersion MediaWiki version to check against, if not the installed one
	 * @return array<int,array{name:string,constraint:string,installed:string}> Requirements
	 *  that are not met
	 */
	public function getUnmet( $requires, ?string $mwVersion = null ): array {
		if ( !is_array( $requires ) ) {
			return [];
		}
		$checks = [
			'MediaWiki' => [ $requires['MediaWiki'] ?? null, $mwVersion ?? $this->mwVersion ],
			'PHP' => [ $requires['platform']['php'] ?? null, $this->phpVersion ],
		];

		$unmet = [];
		foreach ( $checks as $name => [ $constraint, $installed ] ) {
			if ( is_string( $constraint ) && !self::satisfies( $installed, $constraint ) ) {
				$unmet[] = [ 'name' => $name, 'constraint' => $constraint, 'installed' => $installed ];
			}
		}
		return $unmet;
	}

	/**
	 * @param string $constraint Supported PHP versions, such as ">=8.3.0"
	 */
	public function isPhpSupported( string $constraint ): bool {
		return self::satisfies( $this->phpVersion, $constraint );
	}

	private static function satisfies( string $version, string $constraint ): bool {
		// Vendor suffixes such as "-1ubuntu" are not semantic versions.
		$version = preg_match( '/^\d+(\.\d+)*/', $version, $m ) ? $m[0] : $version;
		try {
			return Semver::satisfies( $version, $constraint );
		} catch ( UnexpectedValueException ) {
			// A constraint this cannot read is not a reason to warn.
			return true;
		}
	}
}
