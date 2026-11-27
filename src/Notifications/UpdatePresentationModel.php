<?php

namespace MediaWiki\Extension\VersionWatch\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * How an update notification looks in Echo.
 */
class UpdatePresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function canRender() {
		return true;
	}

	/** @inheritDoc */
	public function getIconType() {
		return 'versionwatch';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		$security = (int)$this->event->getExtraParam( 'security', 0 );
		$names = (array)$this->event->getExtraParam( 'names', [] );
		$count = (int)$this->event->getExtraParam( 'count', count( $names ) );

		// Messages used: notification-header-versionwatch-update,
		// notification-header-versionwatch-update-security
		$msg = $this->msg( $security
			? 'notification-header-versionwatch-update-security'
			: 'notification-header-versionwatch-update'
		);
		return $msg
			->numParams( $security ?: $count )
			->plaintextParams( $this->language->commaList( $names ) )
			->numParams( max( 0, ( $security ?: $count ) - count( $names ) ) );
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return [
			'url' => SpecialPage::getTitleFor( 'VersionWatch' )->getFullURL(),
			'label' => $this->msg( 'notification-link-text-versionwatch-update' )->text(),
		];
	}
}
