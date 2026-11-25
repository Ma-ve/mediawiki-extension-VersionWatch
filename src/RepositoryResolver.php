<?php

namespace MediaWiki\Extension\VersionWatch;

use InvalidArgumentException;

/**
 * Decides which repository and branch an extension or skin is compared against.
 * See RepositorySettings for the format of the settings.
 */
class RepositoryResolver {

	private const KEYS = [ Repository::GERRIT, Repository::GITHUB, 'branch' ];

	/**
	 * @param array<string,mixed> $settings Settings keyed by extension or skin name
	 * @param string $releaseBranch Branch to use for Gerrit projects without a setting
	 */
	public function __construct(
		private readonly array $settings,
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
	 * @return Repository|null Null if the component is set to be skipped
	 * @throws InvalidSettingException
	 */
	public function resolve( Component $component ): ?Repository {
		$setting = $this->settings[$component->name] ?? [];
		if ( $setting === false ) {
			return null;
		}
		self::validate( $setting );

		if ( isset( $setting[Repository::GITHUB] ) ) {
			return new Repository( Repository::GITHUB, $setting[Repository::GITHUB], $setting['branch'] ?? 'HEAD' );
		}

		$default = $this->getDefault( $component );
		return new Repository(
			Repository::GERRIT,
			$setting[Repository::GERRIT] ?? $default->path,
			$setting['branch'] ?? $default->branch
		);
	}

	/**
	 * Where an extension or skin is looked up when nothing is configured for it.
	 */
	public function getDefault( Component $component ): Repository {
		$path = $component->type === Component::SKIN
			? "mediawiki/skins/{$component->name}"
			: "mediawiki/extensions/{$component->name}";
		return new Repository( Repository::GERRIT, $path, $this->releaseBranch );
	}

	/**
	 * @param mixed $setting
	 * @throws InvalidSettingException
	 */
	private static function validate( $setting ): void {
		if ( !is_array( $setting ) ) {
			throw new InvalidSettingException( 'A setting must be false or an object' );
		}
		$unknown = array_diff( array_keys( $setting ), self::KEYS );
		if ( $unknown ) {
			throw new InvalidSettingException( 'Unknown keys: ' . implode( ', ', $unknown ) );
		}
		if ( isset( $setting[Repository::GERRIT] ) && isset( $setting[Repository::GITHUB] ) ) {
			throw new InvalidSettingException( 'A setting cannot have both a Gerrit and a GitHub repository' );
		}
		foreach ( $setting as $key => $value ) {
			if ( !is_string( $value ) || $value === '' ) {
				throw new InvalidSettingException( "'$key' must be a non-empty string" );
			}
		}
	}
}
