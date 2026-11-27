<?php

namespace MediaWiki\Extension\VersionWatch\Notifications;

use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

/**
 * Notifies the users who can see Special:VersionWatch through Echo, when a check finds
 * something that needs attention which the previous check did not.
 *
 * Only used when Echo is installed.
 */
class UpdateNotifier {

	public const EVENT_TYPE = 'versionwatch-update';

	/** Names listed in a notification; the rest are counted. */
	private const MAX_NAMES = 5;

	/** Users notified at most, so a misconfigured right cannot notify the whole wiki. */
	private const MAX_RECIPIENTS = 500;

	public function notify( ?Report $previous, Report $current ): void {
		$news = $this->getNews( $previous, $current );
		if ( !$news ) {
			return;
		}
		$security = array_filter( $news, static fn ( CheckResult $r ) => $r->status === CheckResult::SECURITY );
		$names = array_map( static fn ( CheckResult $r ) => $r->component->name, $security ?: $news );

		Event::create( [
			'type' => self::EVENT_TYPE,
			'extra' => [
				'names' => array_slice( $names, 0, self::MAX_NAMES ),
				'count' => count( $news ),
				'security' => count( $security ),
			],
		] );
	}

	/**
	 * Results that need attention now but did not before, or that became security updates.
	 *
	 * @param Report|null $previous
	 * @param Report $current
	 * @return CheckResult[]
	 */
	public function getNews( ?Report $previous, Report $current ): array {
		$before = [];
		foreach ( $previous?->results ?? [] as $result ) {
			if ( $result->needsAttention() ) {
				$before[$result->component->type][$result->component->name] = $result->status;
			}
		}

		return array_values( array_filter(
			$current->getSortedResults(),
			static function ( CheckResult $result ) use ( $before ) {
				// A module inside another extension is reported through that extension.
				if ( !$result->needsAttention() || $result->component->partOf !== null ) {
					return false;
				}
				$was = $before[$result->component->type][$result->component->name] ?? null;
				return $was === null
					|| ( $result->status === CheckResult::SECURITY && $was !== CheckResult::SECURITY );
			}
		) );
	}

	/**
	 * Echo user locator: everyone in a group that has the versionwatch right.
	 *
	 * @param Event $event
	 * @return User[]
	 */
	public static function locateUsers( Event $event ): array {
		$services = MediaWikiServices::getInstance();
		// Implicit groups such as "user" have no list of members, and granting the right to
		// every user is not a reason to notify them all.
		$groups = array_diff(
			$services->getGroupPermissionsLookup()->getGroupsWithPermission( 'versionwatch' ),
			$services->getUserGroupManager()->listAllImplicitGroups()
		);
		if ( !$groups ) {
			return [];
		}

		$dbr = $services->getConnectionProvider()->getReplicaDatabase();
		$ids = $dbr->newSelectQueryBuilder()
			->select( 'ug_user' )
			->distinct()
			->from( 'user_groups' )
			->where( [ 'ug_group' => array_values( $groups ) ] )
			->andWhere( $dbr->expr( 'ug_expiry', '=', null )->or( 'ug_expiry', '>', $dbr->timestamp() ) )
			->limit( self::MAX_RECIPIENTS )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$userFactory = $services->getUserFactory();
		return array_map( static fn ( $id ) => $userFactory->newFromId( (int)$id ), $ids );
	}
}
