<?php

namespace MediaWiki\Extension\VersionWatch\Source;

/**
 * Recognises security fixes by their commit message. Wikimedia starts the first line of
 * every security fix that goes into a release with "SECURITY:".
 */
class SecurityFixes {

	public static function isSecurityFix( string $subject ): bool {
		return stripos( ltrim( $subject ), 'SECURITY:' ) === 0;
	}
}
