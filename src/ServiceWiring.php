<?php

use MediaWiki\Extension\VersionWatch\CheckRunner;
use MediaWiki\Extension\VersionWatch\InstalledComponents;
use MediaWiki\Extension\VersionWatch\ReportStore;
use MediaWiki\Extension\VersionWatch\RepositoryResolver;
use MediaWiki\Extension\VersionWatch\RepositorySettings;
use MediaWiki\Extension\VersionWatch\Requirements;
use MediaWiki\Extension\VersionWatch\Source\GerritSource;
use MediaWiki\Extension\VersionWatch\Source\GitHubSource;
use MediaWiki\Extension\VersionWatch\Source\ReleaseCycleSource;
use MediaWiki\Extension\VersionWatch\VersionChecker;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

/** @phpcs-require-sorted-array */
return [
	'VersionWatch.CheckRunner' => static function ( MediaWikiServices $services ): CheckRunner {
		return new CheckRunner(
			$services->get( 'VersionWatch.VersionChecker' ),
			$services->get( 'VersionWatch.ReportStore' )
		);
	},

	'VersionWatch.InstalledComponents' => static function ( MediaWikiServices $services ): InstalledComponents {
		$config = $services->getMainConfig();
		return new InstalledComponents(
			ExtensionRegistry::getInstance(),
			$services->getConnectionProvider(),
			$config->get( MainConfigNames::ExtensionDirectory ),
			$config->get( MainConfigNames::StyleDirectory )
		);
	},

	'VersionWatch.ReportStore' => static function ( MediaWikiServices $services ): ReportStore {
		return new ReportStore( $services->getMainObjectStash() );
	},

	'VersionWatch.RepositorySettings' => static function ( MediaWikiServices $services ): RepositorySettings {
		return new RepositorySettings(
			$services->getRevisionLookup(),
			$services->getWikiPageFactory(),
			$services->getTitleFactory(),
			RepositoryResolver::releaseBranchFor( MW_VERSION )
		);
	},

	'VersionWatch.VersionChecker' => static function ( MediaWikiServices $services ): VersionChecker {
		$config = $services->getMainConfig();
		$httpRequestFactory = $services->getHttpRequestFactory();
		$ignoredAuthors = $config->get( 'VersionWatchIgnoredAuthors' );
		$requirements = new Requirements( MW_VERSION, PHP_VERSION );

		return new VersionChecker(
			$services->get( 'VersionWatch.InstalledComponents' ),
			$services->get( 'VersionWatch.RepositorySettings' ),
			new ReleaseCycleSource( $httpRequestFactory ),
			new GerritSource( $httpRequestFactory, $ignoredAuthors, $requirements ),
			new GitHubSource(
				$httpRequestFactory,
				$ignoredAuthors,
				$config->get( 'VersionWatchGitHubToken' ),
				$requirements
			)
		);
	},
];
