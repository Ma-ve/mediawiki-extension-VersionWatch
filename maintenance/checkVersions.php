<?php
/**
 * Check installed software for updates and store the result for Special:VersionWatch.
 *
 * Meant to run daily from cron:
 *
 *     php maintenance/run.php VersionWatch:checkVersions --problems-only
 *
 * With --problems-only the script prints nothing when everything is up to date, so cron
 * only sends mail when something needs attention.
 *
 * @file
 * @ingroup Maintenance
 */

namespace MediaWiki\Extension\VersionWatch\Maintenance;

use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeReport;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeResult;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Message\Message;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class CheckVersions extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'VersionWatch' );
		$this->addDescription( 'Check installed software for updates and store the result ' .
			'for Special:VersionWatch.' );
		$this->addOption( 'problems-only', 'Only print components that are outdated or ' .
			'out of support, and print nothing if there are none.' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$report = $services->get( 'VersionWatch.CheckRunner' )->run();

		$results = $report->getSortedResults();
		if ( $this->hasOption( 'problems-only' ) ) {
			$results = array_filter( $results, static fn ( CheckResult $r ) => $r->needsAttention() );
		}
		$this->printTable( $results );
		$this->printSecurityFixes( $results );
		$this->printUnmetRequirements( $results );
		// Not urgent, so not part of the problems that cron should mail about.
		if ( $report->upgrade !== null && !$this->hasOption( 'problems-only' ) ) {
			$this->printUpgrade( $report->upgrade );
		}
	}

	private function printUpgrade( UpgradeReport $upgrade ): void {
		$this->output( "\nUpgrading to MediaWiki {$upgrade->version}" );
		if ( !$upgrade->phpSupported ) {
			$this->output( " needs PHP {$upgrade->phpRequirement}; this server has " . PHP_VERSION );
		}
		$this->output( ":\n" );
		$blocking = array_filter(
			$upgrade->getSortedResults(),
			static fn ( UpgradeResult $r ) => $r->needsAttention()
		);
		if ( !$blocking ) {
			$this->output( "  Every extension and skin that could be checked is ready.\n" );
		}
		foreach ( $blocking as $result ) {
			$reason = $result->note !== null
				? wfMessage( $result->note, ...$result->noteParams )->inLanguage( 'en' )->text()
				: implode( ', ', array_map(
					static fn ( array $r ) => "needs {$r['name']} {$r['constraint']}",
					$result->unmetRequirements
				) );
			$this->output( "  {$result->component->name}: $reason\n" );
		}
	}

	/**
	 * @param CheckResult[] $results
	 */
	private function printUnmetRequirements( array $results ): void {
		foreach ( $results as $result ) {
			foreach ( $result->unmetRequirements as $requirement ) {
				$this->output( "\n{$result->component->name}: " . wfMessage( 'versionwatch-note-requires' )
					->plaintextParams( $requirement['name'], $requirement['constraint'], $requirement['installed'] )
					->inLanguage( 'en' )
					->text() . "\n" );
			}
		}
	}

	/**
	 * @param CheckResult[] $results
	 */
	private function printSecurityFixes( array $results ): void {
		foreach ( $results as $result ) {
			$fixes = array_filter( $result->changes, static fn ( array $change ) => $change['security'] ?? false );
			if ( !$fixes ) {
				continue;
			}
			$this->output( "\nSecurity fixes for {$result->component->name}:\n" );
			foreach ( $fixes as $fix ) {
				$this->output( "  {$fix['subject']}\n    {$fix['url']}\n" );
			}
		}
	}

	/**
	 * @param CheckResult[] $results
	 */
	private function printTable( array $results ): void {
		if ( !$results ) {
			return;
		}

		$rows = [ [ 'Status', 'Name', 'Installed', 'Latest', 'Note' ] ];
		foreach ( $results as $result ) {
			$component = $result->component;
			$installed = $component->version ?? '-';
			if ( $component->commit !== null ) {
				$installed .= ' (' . substr( $component->commit, 0, 7 )
					. ( $component->bundledWith !== null ? ", bundled with {$component->bundledWith}" : '' )
					. ')';
			}
			$rows[] = [
				$result->status,
				$component->name,
				$installed,
				$result->latest ?? ( $result->latestCommit !== null ? substr( $result->latestCommit, 0, 7 ) : '-' ),
				$this->formatNote( $result ),
			];
		}

		$widths = [];
		foreach ( $rows as $row ) {
			foreach ( $row as $i => $cell ) {
				$widths[$i] = max( $widths[$i] ?? 0, strlen( $cell ) );
			}
		}
		foreach ( $rows as $row ) {
			$line = '';
			foreach ( $row as $i => $cell ) {
				$line .= str_pad( $cell, $widths[$i] + 2 );
			}
			$this->output( rtrim( $line ) . "\n" );
		}
	}

	private function formatNote( CheckResult $result ): string {
		if ( $result->note === null ) {
			return '';
		}
		$params = array_map(
			static fn ( $param ) => is_int( $param ) ? Message::numParam( $param ) : $param,
			$result->noteParams
		);
		return wfMessage( $result->note, ...$params )->inLanguage( 'en' )->text();
	}
}

// @codeCoverageIgnoreStart
$maintClass = CheckVersions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
