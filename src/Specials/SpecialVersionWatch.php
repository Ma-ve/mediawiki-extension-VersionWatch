<?php

namespace MediaWiki\Extension\VersionWatch\Specials;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\InstalledComponents;
use MediaWiki\Extension\VersionWatch\InvalidSettingException;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWiki\Extension\VersionWatch\ReportStore;
use MediaWiki\Extension\VersionWatch\Repository;
use MediaWiki\Extension\VersionWatch\RepositoryResolver;
use MediaWiki\Extension\VersionWatch\RepositorySettings;
use MediaWiki\Extension\VersionWatch\VersionChecker;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use PermissionsError;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Shows the latest report and lets administrators run a new check.
 * Special:VersionWatch/<name> changes where an extension or skin is looked up.
 */
class SpecialVersionWatch extends SpecialPage {

	/** A report older than this means the scheduled check has stopped running. */
	private const STALE_AFTER = 8 * 24 * 3600;

	private const SECTIONS = [
		'versionwatch-section-platform' => [ Component::CORE, Component::PHP, Component::DATABASE ],
		'versionwatch-section-extensions' => [ Component::EXTENSION ],
		'versionwatch-section-skins' => [ Component::SKIN ],
	];

	/** Form value for "do not check"; the other choices are Repository hosts. */
	private const SOURCE_NONE = 'none';

	/** Repository paths and branch names: slash-separated segments of safe characters. */
	private const PATH_PATTERN = '#^[\w.-]+(/[\w.-]+)*$#';
	private const GITHUB_PATH_PATTERN = '#^[\w.-]+/[\w.-]+$#';

	/** Set when this request ran a check, so we do not read it back from the stash. */
	private ?Report $freshReport = null;

	public function __construct(
		private readonly VersionChecker $versionChecker,
		private readonly ReportStore $reportStore,
		private readonly InstalledComponents $installedComponents,
		private readonly RepositorySettings $repositorySettings,
	) {
		parent::__construct( 'VersionWatch', 'versionwatch' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		parent::execute( $subPage );
		$this->getOutput()->addModuleStyles( 'ext.versionWatch.styles' );

		if ( $subPage !== null && $subPage !== '' ) {
			$this->showSettingsForm( $subPage );
		} else {
			$this->showReportPage();
		}
	}

	private function showReportPage(): void {
		$out = $this->getOutput();

		HTMLForm::factory( 'ooui', [], $this->getContext() )
			->setWrapperLegend( false )
			->setSubmitTextMsg( 'versionwatch-check-now' )
			->setSubmitCallback( function () {
				$this->freshReport = $this->runCheck();
				return true;
			} )
			->show();

		$report = $this->freshReport ?? $this->reportStore->get();
		if ( $report === null ) {
			$out->addWikiMsg( 'versionwatch-no-report' );
			return;
		}
		$this->showReport( $report );
	}

	private function runCheck(): Report {
		$report = $this->versionChecker->run();
		$this->reportStore->save( $report );
		return $report;
	}

	private function showReport( Report $report ): void {
		$out = $this->getOutput();

		$out->addHTML( Html::element( 'p', [],
			$this->msg( 'versionwatch-last-checked' )
				->dateTimeParams( $report->timestamp )
				->text()
		) );

		$checkedAt = (int)ConvertibleTimestamp::convert( TS_UNIX, $report->timestamp );
		if ( ConvertibleTimestamp::time() - $checkedAt > self::STALE_AFTER ) {
			$out->addHTML( Html::warningBox( $this->msg( 'versionwatch-stale' )->parse() ) );
		}

		$results = $report->getSortedResults();
		$attention = count( array_filter( $results, static fn ( CheckResult $r ) => $r->needsAttention() ) );
		$out->addHTML( Html::rawElement( 'p', [],
			$this->msg( $attention ? 'versionwatch-attention' : 'versionwatch-attention-none' )
				->numParams( $attention )
				->parse()
		) );

		$resolver = $this->repositorySettings->getResolver();
		foreach ( self::SECTIONS as $heading => $types ) {
			$rows = array_filter(
				$results,
				static fn ( CheckResult $r ) => in_array( $r->component->type, $types, true )
			);
			if ( $rows ) {
				// Messages used: versionwatch-section-platform, versionwatch-section-extensions,
				// versionwatch-section-skins
				$out->addHTML( Html::element( 'h2', [], $this->msg( $heading )->text() ) );
				$out->addHTML( $this->buildTable(
					$rows,
					$heading === 'versionwatch-section-platform' ? null : $resolver
				) );
			}
		}
	}

	/**
	 * @param CheckResult[] $results
	 * @param RepositoryResolver|null $resolver To add a repository column, for extensions and skins
	 * @return string HTML
	 */
	private function buildTable( array $results, ?RepositoryResolver $resolver ): string {
		$columns = [ 'name', 'installed', 'latest', 'status', 'note' ];
		if ( $resolver ) {
			$columns[] = 'repository';
		}
		$header = '';
		foreach ( $columns as $column ) {
			// Messages used: versionwatch-column-name, versionwatch-column-installed,
			// versionwatch-column-latest, versionwatch-column-status, versionwatch-column-note,
			// versionwatch-column-repository
			$header .= Html::element( 'th', [], $this->msg( "versionwatch-column-$column" )->text() );
		}

		$body = '';
		foreach ( $results as $result ) {
			$component = $result->component;
			$cells = Html::element( 'td', [], $component->name ) .
				Html::element( 'td', [], $this->formatRevision(
					$component->version, $component->commit, $component->commitDate, $component->bundledWith
				) ) .
				Html::element( 'td', [], $this->formatRevision(
					$result->latest, $result->latestCommit, $result->latestDate
				) ) .
				Html::rawElement( 'td', [], $this->formatStatus( $result->status ) ) .
				Html::rawElement( 'td', [], $this->formatNote( $result ) );
			if ( $resolver ) {
				$cells .= Html::rawElement( 'td', [], $this->formatRepository( $component, $resolver ) );
			}
			$body .= Html::rawElement( 'tr', [], $cells );
		}

		return Html::rawElement( 'table', [ 'class' => 'wikitable ext-versionwatch-table' ],
			Html::rawElement( 'thead', [], Html::rawElement( 'tr', [], $header ) ) .
			Html::rawElement( 'tbody', [], $body )
		);
	}

	/**
	 * Format a version and/or commit like Special:Version does: "1.8.1 (ced83f8, 23 September 2019)".
	 */
	private function formatRevision(
		?string $version,
		?string $commit,
		?string $date,
		?string $bundledWith = null
	): string {
		$details = [];
		if ( $commit !== null ) {
			$details[] = substr( $commit, 0, 7 );
		}
		if ( $date !== null ) {
			$details[] = $this->getLanguage()->userDate( $date, $this->getUser() );
		}
		if ( $bundledWith !== null ) {
			$details[] = $this->msg( 'versionwatch-bundled-with', $bundledWith )->text();
		}

		if ( $version === null && !$details ) {
			return '–';
		}
		if ( $version === null ) {
			return implode( ', ', $details );
		}
		return $details
			? $version . ' ' . $this->msg( 'parentheses', implode( ', ', $details ) )->text()
			: $version;
	}

	private function formatStatus( string $status ): string {
		// Messages used: versionwatch-status-current, versionwatch-status-outdated,
		// versionwatch-status-unsupported, versionwatch-status-unknown, versionwatch-status-skipped
		// Classes used: ext-versionwatch-status-current, ext-versionwatch-status-outdated,
		// ext-versionwatch-status-unsupported, ext-versionwatch-status-unknown,
		// ext-versionwatch-status-skipped
		return Html::element( 'span',
			[ 'class' => [ 'ext-versionwatch-status', "ext-versionwatch-status-$status" ] ],
			$this->msg( "versionwatch-status-$status" )->text()
		);
	}

	private function formatNote( CheckResult $result ): string {
		if ( $result->note === null ) {
			return '';
		}
		// Parameters come from remote responses and settings, so never parse them as wikitext.
		$params = array_map(
			static fn ( $param ) => is_int( $param )
				? Message::numParam( $param )
				: Message::plaintextParam( (string)$param ),
			$result->noteParams
		);
		return $this->msg( $result->note, ...$params )->parse();
	}

	/**
	 * The repository an extension or skin is looked up in, with a link to change it.
	 *
	 * @return string HTML
	 */
	private function formatRepository( Component $component, RepositoryResolver $resolver ): string {
		if ( $component->partOf !== null ) {
			return $this->msg( 'versionwatch-repository-part-of' )->plaintextParams( $component->partOf )->escaped();
		}
		try {
			$repository = $resolver->resolve( $component );
			if ( $repository === null ) {
				$html = $this->msg( 'versionwatch-repository-not-checked' )->escaped();
			} else {
				$branch = $repository->branch === 'HEAD'
					? $this->msg( 'versionwatch-repository-default-branch' )->text()
					: $repository->branch;
				$html = Html::element( 'a',
						[ 'href' => $repository->getWebUrl(), 'class' => 'external', 'rel' => 'nofollow' ],
						$repository->path
					) . ' ' . $this->msg( 'parentheses', $branch )->escaped();
			}
		} catch ( InvalidSettingException ) {
			$html = $this->msg( 'versionwatch-repository-invalid' )->escaped();
		}

		if ( $this->getAuthority()->probablyCan( 'edit', $this->repositorySettings->getTitle() ) ) {
			$link = $this->getLinkRenderer()->makeKnownLink(
				$this->getPageTitle( $component->name ),
				$this->msg( 'versionwatch-repository-change' )->text()
			);
			$html .= ' ' . $this->msg( 'parentheses' )->rawParams( $link )->escaped();
		}
		return $html;
	}

	private function showSettingsForm( string $name ): void {
		$out = $this->getOutput();
		$out->addBacklinkSubtitle( $this->getPageTitle() );

		$component = $this->findComponent( $name );
		if ( $component === null ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'versionwatch-settings-not-installed' )->plaintextParams( $name )->parse()
			) );
			return;
		}
		if ( $component->partOf !== null ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'versionwatch-settings-part-of' )
					->plaintextParams( $component->name, $component->partOf )
					->parse()
			) );
			return;
		}

		$settingsTitle = $this->repositorySettings->getTitle();
		$permissionStatus = PermissionStatus::newEmpty();
		if ( !$this->getAuthority()->definitelyCan( 'edit', $settingsTitle, $permissionStatus ) ) {
			throw new PermissionsError( 'edit', $permissionStatus );
		}
		$this->checkReadOnly();

		$out->setPageTitleMsg( $this->msg( 'versionwatch-settings-title' )->plaintextParams( $component->name ) );
		$out->addWikiMsg( 'versionwatch-settings-intro', wfEscapeWikiText( $component->name ) );

		$resolver = $this->repositorySettings->getResolver();
		try {
			$resolver->resolve( $component );
		} catch ( InvalidSettingException ) {
			$out->addHTML( Html::warningBox( $this->msg( 'versionwatch-settings-invalid' )->parse() ) );
		}
		$default = $resolver->getDefault( $component );
		[ $source, $path, $branch ] = $this->getFormDefaults( $this->repositorySettings->get( $component->name ) );

		$form = HTMLForm::factory( 'ooui', [
			'source' => [
				'type' => 'radio',
				'label-message' => 'versionwatch-settings-source',
				'options-messages' => [
					'versionwatch-settings-source-gerrit' => Repository::GERRIT,
					'versionwatch-settings-source-github' => Repository::GITHUB,
					'versionwatch-settings-source-none' => self::SOURCE_NONE,
				],
				'default' => $source,
			],
			'path' => [
				'type' => 'text',
				'label-message' => 'versionwatch-settings-path',
				'help-message' => [ 'versionwatch-settings-path-help', $default->path ],
				'default' => $path,
				'hide-if' => [ '===', 'source', self::SOURCE_NONE ],
				'validation-callback' => fn ( $value, array $data ) => $this->validatePath( (string)$value, $data ),
			],
			'branch' => [
				'type' => 'text',
				'label-message' => 'versionwatch-settings-branch',
				'help-message' => [ 'versionwatch-settings-branch-help', $default->branch ],
				'default' => $branch,
				'hide-if' => [ '===', 'source', self::SOURCE_NONE ],
				'validation-callback' => fn ( $value ) => $this->validateBranch( (string)$value ),
			],
		], $this->getContext() );

		$form->setWrapperLegend( false )
			->setSubmitTextMsg( 'versionwatch-settings-save' )
			->setSubmitCallback( fn ( array $data ) => $this->saveSettings( $component, $default, $data ) );

		if ( $form->show() ) {
			$out->redirect( $this->getPageTitle()->getLocalURL() );
		}
	}

	private function findComponent( string $name ): ?Component {
		foreach ( $this->installedComponents->get() as $component ) {
			if ( !$component->isPlatform() && $component->name === $name ) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * @param mixed $setting The stored setting, possibly invalid
	 * @return string[] Source, path and branch to show in the form
	 */
	private function getFormDefaults( $setting ): array {
		if ( $setting === false ) {
			return [ self::SOURCE_NONE, '', '' ];
		}
		$setting = is_array( $setting ) ? array_filter( $setting, 'is_string' ) : [];
		$source = isset( $setting[Repository::GITHUB] ) ? Repository::GITHUB : Repository::GERRIT;
		return [ $source, $setting[$source] ?? '', $setting['branch'] ?? '' ];
	}

	/**
	 * @return bool|Message
	 */
	private function validatePath( string $value, array $data ) {
		$value = trim( $value );
		if ( $data['source'] === Repository::GITHUB ) {
			if ( !preg_match( self::GITHUB_PATH_PATTERN, $value ) ) {
				return $this->msg( 'versionwatch-settings-github-required' );
			}
		} elseif ( $value !== '' && !preg_match( self::PATH_PATTERN, $value ) ) {
			return $this->msg( 'versionwatch-settings-invalid-path' );
		}
		return true;
	}

	/**
	 * @return bool|Message
	 */
	private function validateBranch( string $value ) {
		$value = trim( $value );
		if ( $value !== '' && ( !preg_match( self::PATH_PATTERN, $value ) || str_contains( $value, '..' ) ) ) {
			return $this->msg( 'versionwatch-settings-invalid-branch' );
		}
		return true;
	}

	private function saveSettings( Component $component, Repository $default, array $data ): Status {
		$path = trim( $data['path'] ?? '' );
		$branch = trim( $data['branch'] ?? '' );

		if ( $data['source'] === self::SOURCE_NONE ) {
			$setting = false;
		} elseif ( $data['source'] === Repository::GITHUB ) {
			$setting = array_filter( [ Repository::GITHUB => $path, 'branch' => $branch ], 'strlen' );
		} else {
			// Only store what differs from the default, so a later change of the default
			// (such as a new release branch after upgrading MediaWiki) still applies.
			$setting = array_filter( [
				Repository::GERRIT => $path === $default->path ? '' : $path,
				'branch' => $branch === $default->branch ? '' : $branch,
			], 'strlen' ) ?: null;
		}

		$status = $this->repositorySettings->set(
			$component->name,
			$setting,
			$this->getAuthority(),
			$this->msg( 'versionwatch-settings-summary' )
				->plaintextParams( $component->name )
				->inContentLanguage()
				->text()
		);
		if ( $status->isOK() ) {
			$this->runCheck();
		}
		return Status::wrap( $status );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'wiki';
	}
}
