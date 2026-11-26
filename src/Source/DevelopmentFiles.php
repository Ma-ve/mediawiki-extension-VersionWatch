<?php

namespace MediaWiki\Extension\VersionWatch\Source;

/**
 * Recognises files in an extension repository that only matter for developing it, such as
 * linter configuration, build tooling and tests. Commits that change nothing else do not
 * change how the extension behaves on a wiki.
 */
class DevelopmentFiles {

	/** Files at the top of a repository. */
	private const FILES = [
		'CODE_OF_CONDUCT.md',
		'Doxyfile',
		'Gruntfile.js',
		'README',
		'README.md',
		'SECURITY.md',
		// Extensions declare their dependencies on MediaWiki in extension.json. In composer.json
		// they almost only list development tools, which a bot updates every few weeks.
		'composer.json',
		'jsdoc.json',
		'package-lock.json',
		'package.json',
		'phpunit.xml',
		'phpunit.xml.dist',
	];

	/** Directories at the top of a repository. */
	private const DIRECTORIES = [
		'tests/',
	];

	/**
	 * @param string $path Path relative to the top of the repository
	 */
	public static function contains( string $path ): bool {
		// Dot files and directories, such as .phpcs.xml, .eslintrc.json, .phan/ and .github/
		if ( str_starts_with( $path, '.' ) || in_array( $path, self::FILES, true ) ) {
			return true;
		}
		foreach ( self::DIRECTORIES as $directory ) {
			if ( str_starts_with( $path, $directory ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $paths Files changed by a commit
	 */
	public static function containsAll( array $paths ): bool {
		foreach ( $paths as $path ) {
			if ( !self::contains( $path ) ) {
				return false;
			}
		}
		return true;
	}
}
