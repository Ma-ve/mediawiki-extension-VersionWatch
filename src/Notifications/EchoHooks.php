<?php

namespace MediaWiki\Extension\VersionWatch\Notifications;

use MediaWiki\Permissions\GroupPermissionsLookup;

/**
 * Registers the update notification with Echo. The hook only runs when Echo is installed.
 */
class EchoHooks {

	public function __construct(
		private readonly GroupPermissionsLookup $groupPermissionsLookup,
	) {
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$notificationIcons
	 */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$notificationIcons
	): void {
		$notificationCategories['versionwatch'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-versionwatch',
			// Only offer the preference to those who can receive these notifications.
			'usergroups' => $this->groupPermissionsLookup->getGroupsWithPermission( 'versionwatch' ),
		];
		$notifications[UpdateNotifier::EVENT_TYPE] = [
			'category' => 'versionwatch',
			'group' => 'negative',
			'section' => 'alert',
			'presentation-model' => UpdatePresentationModel::class,
			// A string, since Echo reads an array as a callable followed by options.
			'user-locators' => [ UpdateNotifier::class . '::locateUsers' ],
		];
		$notificationIcons['versionwatch'] = [
			'path' => 'VersionWatch/modules/icons/update.svg',
		];
	}
}
