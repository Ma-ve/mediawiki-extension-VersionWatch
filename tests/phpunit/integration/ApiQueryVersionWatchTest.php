<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Integration;

use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWiki\Tests\Api\ApiTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @group API
 * @group Database
 * @covers \MediaWiki\Extension\VersionWatch\Api\ApiQueryVersionWatch
 */
class ApiQueryVersionWatchTest extends ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'MainObjectStash', new HashBagOStuff() );
	}

	private function storeReport(): void {
		$this->getServiceContainer()->get( 'VersionWatch.ReportStore' )->save( new Report( '20261127100000', [
			new CheckResult(
				component: new Component( Component::EXTENSION, 'ConfirmEdit', '1.6.0', sha1( 'installed' ) ),
				status: CheckResult::SECURITY,
				note: 'versionwatch-note-security-changes',
				noteParams: [ 3, 'REL1_43', 1 ],
				changes: [
					[
						'sha' => sha1( 'a' ),
						'subject' => 'SECURITY: Fix',
						'url' => 'https://a.test',
						'security' => true,
					],
					[ 'sha' => sha1( 'b' ), 'subject' => 'Refactor', 'url' => 'https://b.test', 'security' => false ],
				],
				downloadUrl: 'https://www.mediawiki.org/wiki/Special:ExtensionDistributor/ConfirmEdit'
			),
			new CheckResult( component: new Component( Component::EXTENSION, 'Cite' ), status: CheckResult::CURRENT ),
		] ) );
	}

	public function testReport() {
		$this->storeReport();

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'query', 'meta' => 'versionwatch', 'vwprop' => 'summary|components' ],
			null,
			false,
			$this->getTestSysop()->getAuthority()
		);
		$data = $result['query']['versionwatch'];

		$this->assertSame( '2026-11-27T10:00:00Z', $data['lastchecked'] );
		$this->assertSame( 1, $data['summary']['needsattention'] );
		$this->assertSame( 1, $data['summary']['security'] );
		$this->assertSame( 'ConfirmEdit', $data['components'][0]['name'] );
		$this->assertSame(
			'3 newer changes on the REL1_43 branch, including a security fix.',
			$data['components'][0]['note']
		);
		$this->assertCount( 1, $data['components'][0]['securityfixes'], 'Only security fixes are listed' );
	}

	public function testWarnsWithoutReport() {
		[ $result ] = $this->doApiRequest(
			[ 'action' => 'query', 'meta' => 'versionwatch' ],
			null,
			false,
			$this->getTestSysop()->getAuthority()
		);

		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertArrayNotHasKey( 'versionwatch', $result['query'] ?? [] );
	}

	public function testRequiresRight() {
		$this->storeReport();
		$this->expectException( ApiUsageException::class );

		$this->doApiRequest(
			[ 'action' => 'query', 'meta' => 'versionwatch' ],
			null,
			false,
			$this->getTestUser()->getAuthority()
		);
	}
}
