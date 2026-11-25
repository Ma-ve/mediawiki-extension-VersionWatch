<?php

namespace MediaWiki\Extension\VersionWatch;

/**
 * Per-extension settings for where to look up upstream code.
 */
class RepositorySettings {

	/**
	 * @param array<string,array|false> $settings $wgVersionWatchRepositories
	 * @param string $releaseBranch Branch to use for Gerrit projects without a setting
	 */
	public function __construct(
		private readonly array $settings,
		private readonly string $releaseBranch,
	) {
	}

	public function getResolver(): RepositoryResolver {
		return new RepositoryResolver( $this->settings, $this->releaseBranch );
	}
}
