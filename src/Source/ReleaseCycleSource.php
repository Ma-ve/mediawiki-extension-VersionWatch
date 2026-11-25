<?php

namespace MediaWiki\Extension\VersionWatch\Source;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Checks MediaWiki, PHP and the database server against the release cycle data
 * published by endoflife.date.
 */
class ReleaseCycleSource {

	private const HOST = 'endoflife.date';

	/** Component names mapped to endoflife.date product identifiers. */
	private const PRODUCTS = [
		'MediaWiki' => 'mediawiki',
		'PHP' => 'php',
		'MariaDB' => 'mariadb',
		'MySQL' => 'mysql',
		'PostgreSQL' => 'postgresql',
		'SQLite' => 'sqlite',
	];

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
	) {
	}

	/**
	 * @param Component[] $components Platform components
	 * @return CheckResult[]
	 */
	public function check( array $components ): array {
		$requests = [];
		foreach ( $components as $component ) {
			$product = self::PRODUCTS[$component->name] ?? null;
			if ( $product !== null ) {
				$requests[$product] = [
					'method' => 'GET',
					'url' => 'https://' . self::HOST . "/api/$product.json",
				];
			}
		}
		$responses = $requests
			? $this->httpRequestFactory->createMultiClient()->runMulti( $requests )
			: [];

		$results = [];
		foreach ( $components as $component ) {
			$product = self::PRODUCTS[$component->name] ?? null;
			if ( $product === null ) {
				$results[] = CheckResult::unknown( $component, 'versionwatch-note-unknown-product' );
				continue;
			}
			try {
				$cycles = $this->parseResponse( $responses[$product]['response'] );
				$results[] = $this->evaluate( $component, $cycles );
			} catch ( LookupFailure $e ) {
				$results[] = CheckResult::unknown( $component, $e->messageKey, $e->messageParams );
			}
		}
		return $results;
	}

	private function parseResponse( array $response ): array {
		$cycles = $response['code'] === 200 ? json_decode( $response['body'], true ) : null;
		if ( !is_array( $cycles ) ) {
			throw LookupFailure::forResponse( self::HOST, $response );
		}
		return $cycles;
	}

	private function evaluate( Component $component, array $cycles ): CheckResult {
		$installed = self::normaliseVersion( (string)$component->version );
		$cycle = $installed !== null ? self::findCycle( $cycles, $installed ) : null;
		if ( $installed === null || $cycle === null ) {
			throw new LookupFailure( 'versionwatch-note-unknown-cycle', [ (string)$component->version ] );
		}

		$today = substr( ConvertibleTimestamp::now( TS_ISO_8601 ), 0, 10 );
		// endoflife.date gives either a date, or a boolean when no date is announced.
		$eol = $cycle['eol'];
		$eolDate = is_string( $eol ) ? $eol : null;
		$isEol = $eolDate !== null ? $eolDate <= $today : $eol === true;

		$latest = isset( $cycle['latest'] ) ? (string)$cycle['latest'] : null;
		$latestDate = isset( $cycle['latestReleaseDate'] )
			? ConvertibleTimestamp::convert( TS_MW, $cycle['latestReleaseDate'] . 'T00:00:00Z' )
			: null;

		if ( $isEol ) {
			$status = CheckResult::UNSUPPORTED;
		} elseif ( $latest !== null && version_compare( $installed, $latest, '<' ) ) {
			$status = CheckResult::OUTDATED;
		} else {
			$status = CheckResult::CURRENT;
		}

		if ( $isEol ) {
			$note = $eolDate !== null ? 'versionwatch-note-eol-passed' : 'versionwatch-note-eol';
		} else {
			$note = $eolDate !== null ? 'versionwatch-note-supported-until' : null;
		}

		return new CheckResult(
			$component,
			$status,
			$latest,
			$latestDate ?: null,
			$eolDate,
			$note,
			$eolDate !== null ? [ $eolDate ] : []
		);
	}

	/**
	 * Strip vendor suffixes, such as in "10.11.19-MariaDB-cll-lve-log".
	 */
	private static function normaliseVersion( string $version ): ?string {
		return preg_match( '/^\d+(\.\d+)*/', $version, $m ) ? $m[0] : null;
	}

	/**
	 * Find the release cycle a version belongs to. Cycles are "1.43" for MediaWiki
	 * but "16" for PostgreSQL, so pick the longest cycle that prefixes the version.
	 */
	private static function findCycle( array $cycles, string $version ): ?array {
		$match = null;
		foreach ( $cycles as $cycle ) {
			$name = (string)( $cycle['cycle'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			if ( ( $version === $name || str_starts_with( $version, "$name." ) )
				&& ( $match === null || strlen( $name ) > strlen( (string)$match['cycle'] ) )
			) {
				$match = $cycle;
			}
		}
		return $match;
	}
}
