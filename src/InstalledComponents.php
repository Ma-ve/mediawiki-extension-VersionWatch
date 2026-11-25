<?php

namespace MediaWiki\Extension\VersionWatch;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Utils\GitInfo;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Collects what is installed, from the same sources Special:Version uses.
 */
class InstalledComponents {

	/**
	 * @param ExtensionRegistry $extensionRegistry
	 * @param IConnectionProvider $connectionProvider
	 * @param string $extensionDirectory $wgExtensionDirectory
	 * @param string $styleDirectory $wgStyleDirectory
	 */
	public function __construct(
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly IConnectionProvider $connectionProvider,
		private readonly string $extensionDirectory,
		private readonly string $styleDirectory,
	) {
	}

	/**
	 * @return Component[]
	 */
	public function get(): array {
		$components = [
			new Component( Component::CORE, 'MediaWiki', MW_VERSION, ...$this->getGitInfo( MW_INSTALL_PATH ) ),
			new Component( Component::PHP, 'PHP', PHP_VERSION ),
			$this->getDatabase(),
		];

		foreach ( $this->extensionRegistry->getAllThings() as $credits ) {
			$isSkin = $credits['type'] === 'skin';
			$dir = dirname( $credits['path'] );
			$name = self::getLoadName( $dir, $isSkin ? $this->styleDirectory : $this->extensionDirectory );
			$parent = str_contains( $name, '/' ) ? strstr( $name, '/', true ) : null;
			// A module inside another extension shares that extension's checkout.
			[ $commit, $commitDate ] = $parent === null ? $this->getGitInfo( $dir ) : [ null, null ];

			$components[] = new Component(
				$isSkin ? Component::SKIN : Component::EXTENSION,
				$name,
				$credits['version'] ?? null,
				$commit,
				$commitDate,
				null,
				$parent
			);
		}

		return $components;
	}

	/**
	 * The name passed to wfLoadExtension() or wfLoadSkin(): the directory relative to the
	 * extensions or skins directory. For a module inside another extension that is a path,
	 * such as "ConfirmEdit/Turnstile".
	 */
	private static function getLoadName( string $dir, string $baseDir ): string {
		$prefix = rtrim( $baseDir, '/' ) . '/';
		return str_starts_with( $dir, $prefix ) ? substr( $dir, strlen( $prefix ) ) : basename( $dir );
	}

	private function getDatabase(): Component {
		$db = $this->connectionProvider->getReplicaDatabase();
		$version = $db->getServerVersion();
		$name = match ( $db->getType() ) {
			'mysql' => stripos( $version, 'mariadb' ) !== false ? 'MariaDB' : 'MySQL',
			'postgres' => 'PostgreSQL',
			'sqlite' => 'SQLite',
			default => $db->getType(),
		};
		return new Component( Component::DATABASE, $name, $version );
	}

	/**
	 * @param string $dir
	 * @return array{0:?string,1:?string} Commit SHA-1 and commit date, if $dir is a git checkout
	 *  or an ExtensionDistributor download with gitinfo.json
	 */
	private function getGitInfo( string $dir ): array {
		$gitInfo = new GitInfo( $dir );
		// ExtensionDistributor writes gitinfo.json with the trailing newline of `git rev-parse`,
		// and GitInfo returns it as is.
		$sha = trim( (string)$gitInfo->getHeadSHA1() );
		$date = $gitInfo->getHeadCommitDate();
		return [
			GitInfo::isSHA1( $sha ) ? $sha : null,
			$date ? ConvertibleTimestamp::convert( TS_MW, $date ) : null,
		];
	}
}
