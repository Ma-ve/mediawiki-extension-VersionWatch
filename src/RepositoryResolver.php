<?php

namespace MediaWiki\Extension\VersionWatch;

use InvalidArgumentException;

/**
 * Decides which repository and branch an extension or skin is compared against,
 * based on $wgVersionWatchRepositories.
 */
class RepositoryResolver {

	/**
	 * @param array<string,array|false> $overrides $wgVersionWatchRepositories
	 * @param string $releaseBranch Branch to use for Gerrit projects without an override
	 */
	public function __construct(
		private readonly array $overrides,
		private readonly string $releaseBranch,
	) {
	}

	/**
	 * The Gerrit release branch for a MediaWiki version, such as "REL1_43" for "1.43.2".
	 */
	public static function releaseBranchFor( string $mwVersion ): string {
		if ( !preg_match( '/^(\d+)\.(\d+)/', $mwVersion, $m ) ) {
			throw new InvalidArgumentException( "Unrecognised MediaWiki version '$mwVersion'" );
		}
		return "REL{$m[1]}_{$m[2]}";
	}

	/**
	 * @param Component $component An extension or skin
	 * @return Repository|null Null if the component is configured to be skipped
	 */
	public function resolve( Component $component ): ?Repository {
		$override = $this->overrides[$component->name] ?? [];
		if ( $override === false ) {
			return null;
		}
		if ( !is_array( $override ) ) {
			throw new InvalidArgumentException(
				"\$wgVersionWatchRepositories['{$component->name}'] must be false or an array"
			);
		}

		if ( isset( $override['github'] ) ) {
			return new Repository( Repository::GITHUB, $override['github'], $override['branch'] ?? 'HEAD' );
		}

		$defaultPath = $component->type === Component::SKIN
			? "mediawiki/skins/{$component->name}"
			: "mediawiki/extensions/{$component->name}";
		return new Repository(
			Repository::GERRIT,
			$override['gerrit'] ?? $defaultPath,
			$override['branch'] ?? $this->releaseBranch
		);
	}
}
