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

	public function __construct(
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly IConnectionProvider $connectionProvider,
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
			$dir = dirname( $credits['path'] );
			$components[] = new Component(
				$credits['type'] === 'skin' ? Component::SKIN : Component::EXTENSION,
				basename( $dir ),
				$credits['version'] ?? null,
				...$this->getGitInfo( $dir )
			);
		}

		return $components;
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
		$sha = $gitInfo->getHeadSHA1();
		$date = $gitInfo->getHeadCommitDate();
		return [
			$sha ?: null,
			$date ? ConvertibleTimestamp::convert( TS_MW, $date ) : null,
		];
	}
}
