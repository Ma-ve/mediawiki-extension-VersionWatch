<?php

use MediaWiki\Extension\VersionWatch\InstalledComponents;
use MediaWiki\Extension\VersionWatch\ReportStore;
use MediaWiki\Extension\VersionWatch\RepositoryResolver;
use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Source\ReleaseCycleSource;
use MediaWiki\Extension\VersionWatch\VersionChecker;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

/** @phpcs-require-sorted-array */
return [
	'VersionWatch.ReportStore' => static function ( MediaWikiServices $services ): ReportStore {
		return new ReportStore( $services->getMainObjectStash() );
	},

	'VersionWatch.VersionChecker' => static function ( MediaWikiServices $services ): VersionChecker {
		$config = $services->getMainConfig();
		$httpRequestFactory = $services->getHttpRequestFactory();
		$ignoredAuthors = $config->get( 'VersionWatchIgnoredAuthors' );

		return new VersionChecker(
			new InstalledComponents(
				ExtensionRegistry::getInstance(),
				$services->getConnectionProvider()
			),
			new RepositoryResolver(
				$config->get( 'VersionWatchRepositories' ),
				RepositoryResolver::releaseBranchFor( MW_VERSION )
			),
			new ReleaseCycleSource( $httpRequestFactory ),
			new GerritSource( $httpRequestFactory, $ignoredAuthors ),
			new GitHubSource( $httpRequestFactory, $ignoredAuthors, $config->get( 'VersionWatchGitHubToken' ) )
		);
	},
];
