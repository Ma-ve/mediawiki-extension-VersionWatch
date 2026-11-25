<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Integration;

use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\InstalledComponents;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Integration test because GitInfo reads its configuration from the service container.
 *
 * @covers \MediaWiki\Extension\VersionWatch\InstalledComponents
 */
class InstalledComponentsTest extends MediaWikiIntegrationTestCase {

	/**
	 * @param array[] $credits ExtensionRegistry::getAllThings()
	 * @return Component[] Extensions and skins, by name
	 */
	private function getComponents( array $credits ): array {
		$registry = $this->createMock( ExtensionRegistry::class );
		$registry->method( 'getAllThings' )->willReturn( $credits );
		$db = $this->createMock( IReadableDatabase::class );
		$db->method( 'getType' )->willReturn( 'mysql' );
		$db->method( 'getServerVersion' )->willReturn( '10.11.19-MariaDB-cll-lve-log' );
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->method( 'getReplicaDatabase' )->willReturn( $db );

		$installed = new InstalledComponents(
			$registry, $connectionProvider, '/srv/wiki/extensions', '/srv/wiki/skins'
		);
		$components = [];
		foreach ( $installed->get() as $component ) {
			$components[$component->name] = $component;
		}
		return $components;
	}

	public function testPlatform() {
		$components = $this->getComponents( [] );

		$this->assertSame( MW_VERSION, $components['MediaWiki']->version );
		$this->assertSame( PHP_VERSION, $components['PHP']->version );
		$this->assertSame( '10.11.19-MariaDB-cll-lve-log', $components['MariaDB']->version );
	}

	public function testNamesMatchWhatIsLoaded() {
		$components = $this->getComponents( [
			'Echo' => [ 'path' => '/srv/wiki/extensions/Echo/extension.json', 'type' => 'other' ],
			'Turnstile' => [
				'path' => '/srv/wiki/extensions/ConfirmEdit/Turnstile/extension.json',
				'type' => 'antispam',
			],
			'Vector' => [ 'path' => '/srv/wiki/skins/Vector/skin.json', 'type' => 'skin', 'version' => '1.0.0' ],
			'Elsewhere' => [ 'path' => '/opt/mediawiki-extensions/Elsewhere/extension.json', 'type' => 'other' ],
		] );

		$this->assertNull( $components['Echo']->partOf );
		$this->assertSame( 'ConfirmEdit', $components['ConfirmEdit/Turnstile']->partOf );
		$this->assertSame( Component::SKIN, $components['Vector']->type );
		$this->assertSame( '1.0.0', $components['Vector']->version );
		$this->assertArrayHasKey(
			'Elsewhere', $components, 'Outside the extensions directory, the directory name is used'
		);
	}
}
