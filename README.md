# VersionWatch

A MediaWiki extension that tells you when the software behind your wiki needs updating:
MediaWiki itself, PHP, the database server, and every installed extension and skin.

Results are shown on `Special:VersionWatch` (sysops only) and printed by a maintenance
script, which is meant to run from cron.

Requires MediaWiki 1.43 or later.

## Installation

1. Put this directory in `extensions/VersionWatch`.
2. Add to `LocalSettings.php`:
   ```php
   wfLoadExtension( 'VersionWatch' );
   ```
3. Schedule a daily check, for example in crontab or cPanel's Cron Jobs:
   ```
   15 4 * * * php /path/to/wiki/maintenance/run.php VersionWatch:checkVersions --problems-only
   ```
   With `--problems-only` the script prints nothing while everything is up to date. Cron
   mails any output, so you only get mail when something needs attention.

Sysops can also run a check at any time with the button on `Special:VersionWatch`.

## How components are checked

| Component | Compared against | Statuses |
|---|---|---|
| MediaWiki, PHP, MariaDB, MySQL, PostgreSQL, SQLite | Release data from [endoflife.date](https://endoflife.date) | *Update available* if a newer patch release exists on your branch; *Unsupported* once the branch reaches end of life |
| Extensions and skins installed from git, or downloaded from ExtensionDistributor | The matching release branch (`REL1_43` on 1.43) on Wikimedia Gerrit | *Update available* if the branch has commits you do not have |
| Extensions and skins without git information | The `version` in `extension.json` or `skin.json` on that branch | *Update available* if upstream has a higher version |

Commits from the translation bot land on every branch almost daily, so they do not count
towards *Update available*. See `$wgVersionWatchIgnoredAuthors`.

Extensions bundled with the MediaWiki download usually have neither git information nor a
version, so they show as *Unknown*. They are updated whenever MediaWiki is.

The report is kept in the main object stash, so viewing the special page never waits on
remote requests.

## Configuration

### `$wgVersionWatchRepositories`

Extensions not hosted on Wikimedia Gerrit, or that use a different branch, need an entry here.
Entries are keyed by the name you pass to `wfLoadExtension()`.

```php
$wgVersionWatchRepositories = [
	// Hosted on GitHub; compared against the default branch unless 'branch' is set
	'GTag' => [ 'github' => 'owner/GTag' ],
	// On Gerrit, but only maintained on master
	'SomeExtension' => [ 'branch' => 'master' ],
	// A local fork with no upstream to compare against
	'MaveVariables' => false,
];
```

`Special:VersionWatch` explains when an entry is needed, for example when a repository
cannot be found or has no release branch.

### `$wgVersionWatchGitHubToken`

Without a token, GitHub allows 60 API requests per hour per IP address, shared with
everyone else on that IP address, which can be a problem on shared hosting. Each GitHub-hosted
extension needs up to two requests per check. A
[fine-grained token](https://github.com/settings/personal-access-tokens) with no
permissions is enough.

### `$wgVersionWatchIgnoredAuthors`

Commit authors whose commits do not make an extension outdated. Defaults to
`[ 'Translation updater bot' ]`.

## Rights

`versionwatch` allows viewing `Special:VersionWatch` and running a check. It is granted to
`sysop` by default.

## Development

```
composer install
composer test        # lint, code style, executable bits
composer phan        # static analysis; needs MW_INSTALL_PATH pointing at core
```

Unit tests run from the core directory:

```
php vendor/bin/phpunit extensions/VersionWatch/tests/phpunit/unit
```
