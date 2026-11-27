<?php

namespace MediaWiki\Extension\VersionWatch\Tests;

use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\Http\MultiHttpClient;

/**
 * Serves canned responses to MultiHttpClient::runMulti() by URL.
 */
trait MockHttpTrait {

	/** @var array[] Requests made through the last factory, keyed by URL */
	private array $sentRequests = [];

	/**
	 * @param array<string,array> $responses Responses keyed by URL. Missing fields default to
	 *  an empty 200 response. A request to a URL that is not listed fails the test.
	 */
	private function newHttpRequestFactory( array $responses ): HttpRequestFactory {
		$client = $this->createMock( MultiHttpClient::class );
		$client->method( 'runMulti' )->willReturnCallback( function ( array $requests ) use ( $responses ) {
			foreach ( $requests as &$request ) {
				$url = $request['url'];
				$this->assertArrayHasKey( $url, $responses, "Unexpected request to $url" );
				$this->sentRequests[$url] = $request;
				$request['response'] = $responses[$url] + [
					'code' => 200,
					'reason' => '',
					'headers' => [],
					'body' => '',
					'error' => '',
				];
			}
			return $requests;
		} );
		$client->method( 'run' )->willReturnCallback(
			static fn ( array $request ) => $client->runMulti( [ $request ] )[0]['response']
		);

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'createMultiClient' )->willReturn( $client );
		return $factory;
	}
}
