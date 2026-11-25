<?php

namespace MediaWiki\Extension\VersionWatch\Specials;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Component;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWiki\Extension\VersionWatch\ReportStore;
use MediaWiki\Extension\VersionWatch\VersionChecker;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Shows the latest report and lets administrators run a new check.
 */
class SpecialVersionWatch extends SpecialPage {

	/** A report older than this means the scheduled check has stopped running. */
	private const STALE_AFTER = 8 * 24 * 3600;

	private const SECTIONS = [
		'versionwatch-section-platform' => [ Component::CORE, Component::PHP, Component::DATABASE ],
		'versionwatch-section-extensions' => [ Component::EXTENSION ],
		'versionwatch-section-skins' => [ Component::SKIN ],
	];

	/** Set when this request ran a check, so we do not read it back from the stash. */
	private ?Report $freshReport = null;

	public function __construct(
		private readonly VersionChecker $versionChecker,
		private readonly ReportStore $reportStore,
	) {
		parent::__construct( 'VersionWatch', 'versionwatch' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		parent::execute( $subPage );
		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.versionWatch.styles' );

		HTMLForm::factory( 'ooui', [], $this->getContext() )
			->setWrapperLegend( false )
			->setSubmitTextMsg( 'versionwatch-check-now' )
			->setSubmitCallback( function () {
				$this->freshReport = $this->versionChecker->run();
				$this->reportStore->save( $this->freshReport );
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

		foreach ( self::SECTIONS as $heading => $types ) {
			$rows = array_filter(
				$results,
				static fn ( CheckResult $r ) => in_array( $r->component->type, $types, true )
			);
			if ( $rows ) {
				// Messages used: versionwatch-section-platform, versionwatch-section-extensions,
				// versionwatch-section-skins
				$out->addHTML( Html::element( 'h2', [], $this->msg( $heading )->text() ) );
				$out->addHTML( $this->buildTable( $rows ) );
			}
		}
	}

	/**
	 * @param CheckResult[] $results
	 * @return string HTML
	 */
	private function buildTable( array $results ): string {
		$header = '';
		foreach ( [ 'name', 'installed', 'latest', 'status', 'note' ] as $column ) {
			// Messages used: versionwatch-column-name, versionwatch-column-installed,
			// versionwatch-column-latest, versionwatch-column-status, versionwatch-column-note
			$header .= Html::element( 'th', [], $this->msg( "versionwatch-column-$column" )->text() );
		}

		$body = '';
		foreach ( $results as $result ) {
			$component = $result->component;
			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $component->name ) .
				Html::element( 'td', [], $this->formatRevision(
					$component->version, $component->commit, $component->commitDate
				) ) .
				Html::element( 'td', [], $this->formatRevision( $result->latest, null, $result->latestDate ) ) .
				Html::rawElement( 'td', [], $this->formatStatus( $result->status ) ) .
				Html::rawElement( 'td', [], $this->formatNote( $result ) )
			);
		}

		return Html::rawElement( 'table', [ 'class' => 'wikitable ext-versionwatch-table' ],
			Html::rawElement( 'thead', [], Html::rawElement( 'tr', [], $header ) ) .
			Html::rawElement( 'tbody', [], $body )
		);
	}

	/**
	 * Format a version and/or commit like Special:Version does: "1.8.1 (ced83f8, 23 September 2019)".
	 */
	private function formatRevision( ?string $version, ?string $commit, ?string $date ): string {
		$details = [];
		if ( $commit !== null ) {
			$details[] = substr( $commit, 0, 7 );
		}
		if ( $date !== null ) {
			$details[] = $this->getLanguage()->userDate( $date, $this->getUser() );
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
		// Parameters come from remote responses and configuration, so never parse them as wikitext.
		$params = array_map(
			static fn ( $param ) => is_int( $param )
				? Message::numParam( $param )
				: Message::plaintextParam( (string)$param ),
			$result->noteParams
		);
		return $this->msg( $result->note, ...$params )->parse();
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
