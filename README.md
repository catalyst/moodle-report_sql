# SQL Report

[![Moodle Plugin CI](https://github.com/catalyst/moodle-report_sql/actions/workflows/ci.yml/badge.svg)](https://github.com/catalyst/moodle-report_sql/actions/workflows/ci.yml)
[![GitHub Release](https://img.shields.io/github/release/catalyst/moodle-report_sql.svg)](https://github.com/catalyst/moodle-report_sql/releases)
[![Moodle Support](https://img.shields.io/badge/Moodle-%3E%3D%204.5-blue)](https://moodle.org/plugins/report_sql)

*Reportbuilder ... the SeQueL (Thanks to Adam Jenkins)*

**SQL Report** lets you turn a SQL query into a fully configurable Moodle report — no programming required. Write your query, click **Publish**, and the plugin creates a Report Builder report your colleagues can run, filter, chart, schedule, and export.

📖 **[Read the full user documentation →](docs/userdocs.md)**

> ⚠️ Reporting tools can expose sensitive data. Always test who can see a report before sharing it.

## 📖 Documentation

The [full user documentation](docs/userdocs.md) covers the edit form, writing SQL, placeholders, the SQL editor, AI generation, audiences, charts, scheduled emails, import/export, troubleshooting, and more.

## What it does

- Write a `SELECT` query (or generate one with AI) and publish it as a Report Builder report.
- Choose columns, filters, sorting, and charts through the standard Report Builder UI.
- Control who can open each report via audiences (course participants, roles, cohorts, all users).
- Schedule reports to be emailed on a recurring basis.
- Cross-database placeholders (`%%TIMESTAMP%%`, `%%NOW%%`, `%%COURSEID%%`, `%%CASE%%`, …) keep queries portable across MySQL/MariaDB and PostgreSQL.
- Import and export report views as portable JSON.

## Companion plugins

A published report can also be shown **inline on a page**, rendered through the same per-viewer
access path as the report viewer — each person sees only the rows they are allowed to:

- **[filter_sqlreports](https://github.com/marcusgreen/moodle-filter_sqlreports)** — a text filter
  that embeds a report in any Moodle text (a label, Page, Book chapter, forum post, or course
  summary) with a `[[sqlreport:ID]]` marker.
- **[block_sqlreports](https://github.com/marcusgreen/moodle-block_sqlreports)** — a block that pins
  one published report (as a table or chart) on a course page, the site front page, or the Dashboard.

See [Showing a report on a page](docs/userdocs.md#showing-a-report-on-a-page-block-and-filter) in the
user documentation for setup.

## Requirements

- Moodle 4.5 – 5.2
- A database account with permission to create and drop database views.

Check the privilege from **Site admin → Reports → SQL Report → Run database view privilege test**.

### SQL validation

PostgreSQL queries are syntax-checked with the bundled Composer library
[`sad_spirit/pg_builder`](https://github.com/sad-spirit/pg-builder) after Moodle
table placeholders and SQL tokens are compiled. Its abstract syntax tree is
checked recursively for read-only query structure, denied tables/columns,
schema-qualified tables and explicit row locks (including `FOR SHARE`).
Both parsers also reject PostgreSQL XML query/table/schema/database/cursor export
functions, whose hidden reads would bypass the table and column checks. This
includes `query_to_xml()` even when its SQL argument is escaped or constructed.
MySQL/MariaDB retains the existing Greenlion parser.
MySQL, MariaDB and Aurora MySQL also apply recursive source-table, column and
function checks to its compiled query tree, including subqueries, CTEs and UNION
branches. Executable comments (`/*! ... */` and `/*M! ... */`), explicit row
locks, variable assignments (`:=`) and `LOAD_FILE()` are rejected. Ordinary
comments and optimizer hints remain allowed. Greenlion is non-validating:
syntax checking still requires the live database, and this does not certify
every server version or SQL mode.
The bundled 2.4 release preserves PHP 8.1 compatibility and covers PostgreSQL 16
query grammar; newer PostgreSQL syntax may be rejected even on a newer server.

Both paths retain the shared regex rules and live database checks. Parsing does
not verify table/column existence, function signatures, permissions or query
cost, and is not a guarantee that a SELECT function has no side effects.
PostgreSQL syntax errors now include the parser's location/context; malformed
joins receive a parser error rather than the MySQL-specific missing-ON hint.
The parser dependencies are bundled: site administrators do not need to run
Composer or install another native extension.

### Updating PHP dependencies

The plugin's root `composer.json` manages both SQL parsers. The root
`composer.lock` and `vendor/` directory are committed so the plugin remains a
single installable package, with no Composer step required on the Moodle site.
Both parsers load through `vendor/autoload.php`.
Composer resolves dependencies against PHP 8.1.0 via `config.platform.php`,
even when updates are run on a newer PHP version, to preserve the PHP 8.1 floor.

The bundled Greenlion 4.7.0 includes an obsolete development-only CodeSniffer
file with curly-brace string offsets, which PHP 8 cannot parse. The latest
5.0.0 release still contains this file, so upgrading does not fix full-tree
linting. A local four-line patch replaces those offsets with square brackets;
`cweagans/composer-patches` 2.x applies it when installing the dependency.
`patches.lock.json` locks the patch checksum. No parser runtime code is changed.

The bundled `pg_builder` 2.4.0 also needs a local compatibility patch: its
implicitly nullable parameters are made explicitly nullable to prevent PHP 8.4
deprecations. This preserves the existing accepted arguments and PHP 8.1
support; upstream 3.x requires PHP 8.2. The patch covers all affected signatures,
including interfaces and overrides, rather than only those exercised by tests.
The plugin's PHPUnit configuration still restricts deprecation reporting to
first-party source files, but neither patch relies on suppressing deprecations.

From the plugin directory, restore the locked dependencies with:

```bash
composer install --no-dev --prefer-dist
```

Dependency maintenance requires Git and Composer 2.2 or newer for patching.
Keep Composer plugins enabled (do not use `--no-plugins`). These tools are not
required on Moodle sites installing the bundled plugin.

To update the parsers within their supported version constraints:

```bash
composer update greenlion/php-sql-parser sad_spirit/pg_builder --no-dev --prefer-dist
```

After changing a patch definition or patch file, regenerate its lock and reapply:

```bash
composer patches-relock
composer patches-repatch
```

Keep `sad_spirit/pg_builder` on 2.x to preserve PHP 8.1 compatibility.
After updates, refresh the versions in `thirdpartylibs.xml` (including the
Composer runtime entries when regenerating with a newer Composer release), run
`tests/sql_validator_test.php` and `tests/postgres_validator_test.php` through
Moodle's PHPUnit runner on PostgreSQL and MySQL/MariaDB, and commit the changed
lock files, local patches and vendor files together. Include the patch tooling
in `thirdpartylibs.xml` too. Only remove the Greenlion patch once the chosen
upstream release passes full-tree PHP lint without it. Remove the `pg_builder`
patch only when an upstream release supports the full PHP range without
implicit-nullability deprecations.

## Installation

1. Copy the `sql` folder into `<moodleroot>/report/` (so the plugin lives at `<moodleroot>/report/sql/`).
2. Go to **Site admin → Notifications** to complete the install.
3. Run the privilege test above to confirm everything works.

## Who can do what

| Role | What they can do |
|---|---|
| **Manager** (site-wide) | Write queries, publish/unpublish reports, see all reports |
| **Authenticated user** | Run published reports they have been given access to |
| **Teacher** (within a course) | Run reports published for their course |

Permissions are assigned at **Site admin → Users → Permissions → Define roles**. See the [user documentation](docs/userdocs.md#who-can-do-what-capabilities) for the full capability model.

## License

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html).
