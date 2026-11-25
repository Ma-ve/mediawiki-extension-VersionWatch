<?php

namespace MediaWiki\Extension\VersionWatch;

/**
 * Where the upstream code of an extension or skin lives.
 */
class Repository {

	public const GERRIT = 'gerrit';
	public const GITHUB = 'github';

	/**
	 * @param string $host One of the class constants
	 * @param string $path Project path, such as "mediawiki/extensions/Echo" on Gerrit
	 *  or "owner/repo" on GitHub
	 * @param string $branch Branch to compare against; "HEAD" means the default branch
	 */
	public function __construct(
		public readonly string $host,
		public readonly string $path,
		public readonly string $branch,
	) {
	}
}
