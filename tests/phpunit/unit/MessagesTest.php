<?php

namespace MediaWiki\Extension\VersionWatch\Tests\Unit;

use MediaWikiUnitTestCase;

/**
 * Translators on translatewiki.net need documentation for every message.
 *
 * @coversNothing
 */
class MessagesTest extends MediaWikiUnitTestCase {

	private static function keys( string $file ): array {
		$messages = json_decode( file_get_contents( __DIR__ . "/../../../i18n/$file" ), true );
		unset( $messages['@metadata'] );
		return array_keys( $messages );
	}

	public function testEveryMessageIsDocumented() {
		$this->assertSame( [], array_values( array_diff( self::keys( 'en.json' ), self::keys( 'qqq.json' ) ) ) );
	}

	public function testNoDocumentationForRemovedMessages() {
		$this->assertSame( [], array_values( array_diff( self::keys( 'qqq.json' ), self::keys( 'en.json' ) ) ) );
	}
}
