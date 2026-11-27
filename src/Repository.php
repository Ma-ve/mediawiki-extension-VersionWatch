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

	/**
	 * Where to browse the branch in a web browser.
	 */
	public function getWebUrl(): string {
		if ( $this->host === self::GITHUB ) {
			return "https://github.com/{$this->path}" . ( $this->branch === 'HEAD' ? '' : "/tree/{$this->branch}" );
		}
		return "https://gerrit.wikimedia.org/r/plugins/gitiles/{$this->path}/+/refs/heads/{$this->branch}";
	}

	public function getCommitUrl( string $sha ): string {
		return $this->host === self::GITHUB
			? "https://github.com/{$this->path}/commit/$sha"
			: "https://gerrit.wikimedia.org/r/plugins/gitiles/{$this->path}/+/$sha";
	}

	/**
	 * Where to see every commit on the branch that $sha does not have.
	 */
	public function getChangesUrl( string $sha ): string {
		return $this->host === self::GITHUB
			? "https://github.com/{$this->path}/compare/$sha...{$this->branch}"
			: "https://gerrit.wikimedia.org/r/plugins/gitiles/{$this->path}/+log/$sha..{$this->branch}";
	}
}
