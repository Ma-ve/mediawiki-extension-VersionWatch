<?php

namespace MediaWiki\Extension\VersionWatch;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\Content;
use MediaWiki\Content\JsonContent;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use StatusValue;
use stdClass;

/**
 * Per-extension settings for where to look up upstream code.
 *
 * They are kept as JSON on MediaWiki:VersionWatch.json, so every change has page history,
 * shows up in recent changes and can be reverted. The page maps an extension or skin name
 * to false (do not check it) or to an array with 'gerrit' or 'github' and/or 'branch'.
 */
class RepositorySettings {

	public const PAGE_NAME = 'VersionWatch.json';

	/** @var array<string,mixed>|null */
	private ?array $settings = null;

	/**
	 * @param RevisionLookup $revisionLookup
	 * @param WikiPageFactory $wikiPageFactory
	 * @param TitleFactory $titleFactory
	 * @param string $releaseBranch Branch to use for Gerrit projects without a setting
	 */
	public function __construct(
		private readonly RevisionLookup $revisionLookup,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFactory $titleFactory,
		private readonly string $releaseBranch,
	) {
	}

	public function getTitle(): Title {
		return $this->titleFactory->makeTitle( NS_MEDIAWIKI, self::PAGE_NAME );
	}

	/**
	 * @return array<string,mixed> Settings keyed by extension or skin name. Values are not
	 *  validated, since the page can be edited by hand; RepositoryResolver does that.
	 */
	public function getAll(): array {
		$this->settings ??= self::decode(
			$this->revisionLookup->getRevisionByTitle( $this->getTitle() )?->getContent( SlotRecord::MAIN )
		);
		return $this->settings;
	}

	/**
	 * @param string $name Extension or skin name
	 * @return mixed Null if there is no setting
	 */
	public function get( string $name ) {
		return $this->getAll()[$name] ?? null;
	}

	public function getResolver(): RepositoryResolver {
		return new RepositoryResolver( $this->getAll(), $this->releaseBranch );
	}

	/**
	 * Change the setting for one extension or skin by editing the settings page.
	 *
	 * @param string $name Extension or skin name
	 * @param array|false|null $setting Null removes the setting, so the default applies
	 * @param Authority $performer
	 * @param string $summary Edit summary
	 * @return StatusValue
	 */
	public function set( string $name, $setting, Authority $performer, string $summary ): StatusValue {
		$title = $this->getTitle();
		$permissionStatus = PermissionStatus::newEmpty();
		if ( !$performer->authorizeWrite( 'edit', $title, $permissionStatus ) ) {
			return $permissionStatus;
		}

		$updater = $this->wikiPageFactory->newFromTitle( $title )->newPageUpdater( $performer );
		// Start from the revision the update will be based on, so concurrent changes to
		// other extensions are kept.
		$settings = self::decode( $updater->grabParentRevision()?->getContent( SlotRecord::MAIN ) );
		if ( $setting === null ) {
			unset( $settings[$name] );
		} else {
			$settings[$name] = $setting;
		}
		ksort( $settings, SORT_STRING | SORT_FLAG_CASE );

		$json = FormatJson::encode( $settings ?: new stdClass(), "\t", FormatJson::ALL_OK );
		$updater->setContent( SlotRecord::MAIN, new JsonContent( $json ) );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( $summary ) );
		$this->settings = null;

		return $updater->getStatus();
	}

	private static function decode( ?Content $content ): array {
		if ( !$content instanceof JsonContent ) {
			return [];
		}
		$data = json_decode( $content->getText(), true );
		return is_array( $data ) ? $data : [];
	}
}
