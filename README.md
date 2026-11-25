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
| Extensions and skins that came with the MediaWiki download | The commit your MediaWiki release pinned them to, compared with the release branch like a git checkout | *Update available* if the branch has commits you do not have |
| Other extensions and skins without git information | The `version` in `extension.json` or `skin.json` on that branch | *Update available* if upstream has a higher version |

Commits from the translation bot land on every branch almost daily, so they do not count
towards *Update available*. See `$wgVersionWatchIgnoredAuthors`.

Extensions bundled with the MediaWiki download have no git information, but each MediaWiki
release tag pins the exact commit of every bundled extension and skin. VersionWatch assumes
such an extension is the copy that came with your MediaWiki version, and checks that commit.
If you replaced a bundled extension with a newer copy that has no git information, install
it from git or ExtensionDistributor instead, so its own commit is used.

The report is kept in the main object stash, so viewing the special page never waits on
remote requests.

## Where extensions are looked up

By default an extension or skin is looked up on Wikimedia Gerrit, on the release branch
matching your MediaWiki version. For anything else, such as an extension hosted on GitHub,
one that is only maintained on master, or local code without an upstream, use the *change*
link in the Repository column of `Special:VersionWatch`. The details column says when that
is needed.

Settings are saved as JSON on `MediaWiki:VersionWatch.json`, so every change appears in
recent changes and the page history, and can be reverted. Anyone who can edit that page can
change them; by default that is sysops and interface administrators. The page can also be
edited by hand:

```json
{
	"GTag": { "github": "owner/GTag" },
	"MaveVariables": false,
	"SomeExtension": { "branch": "master" }
}
```

Each entry is keyed by the name passed to `wfLoadExtension()` and is either `false` (do not
check) or an object with `gerrit` or `github` (the repository path) and/or `branch`. On GitHub
the default branch is used unless `branch` is set.

## Configuration

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

Tests run from the core directory. The integration tests need an installed wiki:

```
php vendor/bin/phpunit extensions/VersionWatch/tests/phpunit
```

## Releases

Every push to `main` runs the checks and uploads the extension as a build artifact.

To release, run the *Release* workflow from the Actions tab and choose which part of the
version to increment. It updates `version` in `extension.json`, commits and tags that
change on `main`, and attaches `VersionWatch-<version>.zip` to a new GitHub release.
Development files are left out of the zip through `.gitattributes`.
