<?php

namespace MediaWiki\Extension\VersionWatch\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\VersionWatch\CheckResult;
use MediaWiki\Extension\VersionWatch\Report;
use MediaWiki\Extension\VersionWatch\ReportStore;
use MediaWiki\Extension\VersionWatch\Upgrade\UpgradeResult;
use MediaWiki\Message\Message;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * The latest report of Special:VersionWatch, for monitoring tools and scripts.
 *
 * It does not run a check: that is up to the scheduled maintenance script or the special page.
 */
class ApiQueryVersionWatch extends ApiQueryBase {

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly ReportStore $reportStore,
	) {
		parent::__construct( $query, $moduleName, 'vw' );
	}

	/** @inheritDoc */
	public function execute() {
		$this->checkUserRightsAny( 'versionwatch' );
		$prop = array_flip( $this->extractRequestParams()['prop'] );

		$report = $this->reportStore->get();
		if ( $report === null ) {
			$this->addWarning( 'apiwarn-versionwatch-noreport' );
			return;
		}

		$data = [ 'lastchecked' => ConvertibleTimestamp::convert( TS_ISO_8601, $report->timestamp ) ];
		if ( isset( $prop['summary'] ) ) {
			$data['summary'] = $this->getSummary( $report );
		}
		if ( isset( $prop['components'] ) ) {
			$data['components'] = array_map( [ $this, 'formatResult' ], $report->getSortedResults() );
			ApiResult::setIndexedTagName( $data['components'], 'component' );
		}
		if ( isset( $prop['upgrade'] ) && $report->upgrade !== null ) {
			$upgrade = $report->upgrade;
			$data['upgrade'] = [
				'version' => $upgrade->version,
				'eol' => $upgrade->eol,
				'lts' => $upgrade->lts,
				'phprequirement' => $upgrade->phpRequirement,
				'phpsupported' => $upgrade->phpSupported,
				'components' => array_map( fn ( UpgradeResult $result ) => [
					'type' => $result->component->type,
					'name' => $result->component->name,
					'status' => $result->status,
					'note' => $this->formatNote( $result->note, $result->noteParams ),
					'unmetrequirements' => $result->unmetRequirements,
				], $upgrade->getSortedResults() ),
			];
			ApiResult::setIndexedTagName( $data['upgrade']['components'], 'component' );
		}

		$this->getResult()->addValue( 'query', $this->getModuleName(), $data );
	}

	private function getSummary( Report $report ): array {
		$counts = array_fill_keys( CheckResult::SEVERITY_ORDER, 0 );
		foreach ( $report->results as $result ) {
			$counts[$result->status]++;
		}
		return [
			'needsattention' => $counts[CheckResult::SECURITY] + $counts[CheckResult::UNSUPPORTED]
				+ $counts[CheckResult::OUTDATED],
			'security' => $counts[CheckResult::SECURITY],
			'statuses' => $counts,
		];
	}

	private function formatResult( CheckResult $result ): array {
		$component = $result->component;
		return [
			'type' => $component->type,
			'name' => $component->name,
			'version' => $component->version,
			'commit' => $component->commit,
			'status' => $result->status,
			'latest' => $result->latest,
			'latestcommit' => $result->latestCommit,
			'eol' => $result->eol,
			'note' => $this->formatNote( $result->note, $result->noteParams ),
			'securityfixes' => array_values( array_filter(
				$result->changes,
				static fn ( array $change ) => $change['security'] ?? false
			) ),
			'changesurl' => $result->changesUrl,
			'downloadurl' => $result->downloadUrl,
			'unmetrequirements' => $result->unmetRequirements,
		];
	}

	private function formatNote( ?string $key, array $params ): ?string {
		if ( $key === null ) {
			return null;
		}
		return $this->msg( $key, ...array_map(
			static fn ( $param ) => is_int( $param )
				? Message::numParam( $param )
				: Message::plaintextParam( (string)$param ),
			$params
		) )->text();
	}

	/** @inheritDoc */
	protected function getAllowedParams() {
		return [
			'prop' => [
				ParamValidator::PARAM_TYPE => [ 'summary', 'components', 'upgrade' ],
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => 'summary|components',
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&meta=versionwatch&vwprop=summary'
				=> 'apihelp-query+versionwatch-example-summary',
			'action=query&meta=versionwatch&vwprop=summary|components|upgrade'
				=> 'apihelp-query+versionwatch-example-all',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:VersionWatch#API';
	}
}
