<?php

namespace MediaWiki\Extension\VersionWatch\Source;

use RuntimeException;

/**
 * Thrown while interpreting an upstream response that does not allow a conclusion.
 * Carries the message that explains why to the administrator.
 */
class LookupFailure extends RuntimeException {

	public function __construct(
		public readonly string $messageKey,
		public readonly array $messageParams = [],
	) {
		parent::__construct( $messageKey );
	}

	/**
	 * Failure for a response that was not one of the expected outcomes.
	 *
	 * @param string $host Host name, for display
	 * @param array $response MultiHttpClient response map
	 */
	public static function forResponse( string $host, array $response ): self {
		$reason = $response['code']
			? "HTTP {$response['code']}"
			: $response['error'];
		return new self( 'versionwatch-note-request-failed', [ $host, $reason ] );
	}
}
