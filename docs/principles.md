# Report SQL: security principles

This page records the first principles behind what Report SQL refuses to run, and why. It is the
reference to check before changing the validator (`classes/local/sql/validator.php`,
`postgres_validator.php`, `mysql_validator.php`) or the default denylists in `settings.php`.

Each rule is written as a MUST / MUST NOT so it can be mapped to a test. Anything that relaxes a
rule needs a deliberate decision, not a convenience tweak.

## Threat model

Report authors are trusted to write queries, but not trusted with everything the database holds.
A report author is not a site administrator, and an author's mistake should not become a data leak.

Report SQL protects against:

- Authors (or an imported report) reading **credentials, secrets, tokens, sessions or site
  configuration**, even by accident.
- Authors **changing data or schema**, or leaving locks behind.
- **Hidden reads**: ways to reach data that the table and column checks would otherwise see.
- A viewer seeing more than the report's **audience** is meant to see.

It does not protect against:

- Expensive queries. Cost is not analysed or guaranteed.
- A `SELECT` function with side effects. The validator cannot certify this. See "Non-goals".
- Reading ordinary user data an author has legitimate access to. Reports expose sensitive data
  by design, so audience control is the safeguard there.

## Principles

### 1. Read-only, one statement

- A report MUST be a single `SELECT`. No DML, DDL, `CALL`, `COPY`, `GRANT`, transaction control,
  `INTO`/`OUTFILE`, or variable assignment (`:=`).
- A report MUST NOT contain more than one statement, whether `;`-separated or nested at top level.
- Explicit row locks (`FOR UPDATE`, `FOR SHARE`, `LOCK`) MUST be rejected. A report must not block
  the site.

*Why:* the report runs as the Moodle DB user, which can write everything.

### 2. No credentials, secrets or sessions

- Tables that hold credentials, tokens, keys, sessions or site configuration MUST be denied.
  The default list lives in `validator::DENY_TABLES` and is editable through the `denytables`
  setting. It currently covers:
    - core config: `config`, `config_plugins`, `config_log`, `adminpresets_it`
    - passwords: `user_password_history`, `user_password_resets`
    - sessions: `sessions`, `mnet_session`
    - OAuth2: issuer, endpoint, field mapping, access and refresh tokens, system account, linked
      logins, backpack tokens
    - web service tokens: `external_tokens`, `external_services`, `external_services_users`,
      `lti_access_tokens`, `enrol_lti_lti2_share_key`
    - keys and secrets: `user_private_key`, `tool_mfa_secrets`, `messageinbound_datakeys`,
      `message_airnotifier_devices`
    - tasks: `task_adhoc`
- Sensitive column names MUST be denied wherever they appear (`denycolumns`: `password`, `secret`,
  `token`, `sesskey`, `salt`, `hash`, `privatekey` and similar).
- Column denial MUST apply to the SQL source, not only the output name. `SELECT password AS pw`
  must be rejected.
- Denial MUST also apply to quoted identifiers (`"token"`, `` `token` ``).

*Why:* a leaked token or key is a credential for the whole site, not just a data exposure.

### 3. No hidden reads

- The validator MUST reject any construct that reads data outside the visible table and column
  checks:
    - PostgreSQL `query_to_xml()`, `table_to_xml()`, `schema_to_xml()`, `database_to_xml()`,
      `cursor_to_xml()` and their `*_xmlschema` variants. These run an SQL string or read a
      relation the parser never sees.
    - MySQL/MariaDB executable comments (`/*! ... */`, `/*M! ... */`) and `LOAD_FILE()`.
- Schema-qualified tables MUST be rejected, so a report cannot cross into another schema or database.
- Table checks MUST be recursive (subqueries, CTEs, `UNION` branches).

*Why:* a denylist is only as good as its coverage. Any path that reads a table without naming it
in the AST defeats it.

### 4. The denylist fails safe for admins, closed for authors

- The `denytables` and `denycolumns` settings are the source of truth at runtime. Only an admin
  can edit them.
- If `denytables` has never been saved, the built-in baseline MUST apply.
- Adding to a denylist is cheap. Removing from one is a security decision and needs a reason.

### 5. Defence in depth, not a single check

- Several independent layers MUST remain: keyword denylist, parser/AST checks, table and column
  denylists, quoted-identifier checks and the live database check.
- A layer MUST NOT be removed because another layer "should" catch the same thing. The parser alone
  does not block, for example, `INTO OUTFILE`.

### 6. Visibility is the author's responsibility, enforced by the plugin

- A report MUST be visible only to its configured audience. The plugin enforces the
  "Restrict to viewing user" and audience controls. It does not rely on authors remembering them.
- Imported reports (Configurable Reports, Ad-hoc database queries) MUST pass the same validation as
  hand-written ones, and MUST land as drafts.

### 7. Reject loudly, never silently rewrite

- Where a rule fires, the validator MUST fail with a named error. It MUST NOT quietly strip or
  alter the query and run something the author did not write.
- Portable `%%TOKEN%%` expansion is the one sanctioned rewrite. It is deterministic and documented.

## Non-goals and known limits

- Parsing does not verify that tables or columns exist, function signatures, permissions or query
  cost.
- A `SELECT` can call a function with side effects. The validator cannot prove otherwise.
- Greenlion (MySQL) is a non-validating parser. Syntax checking still needs the live DB. The bundled
  PostgreSQL grammar covers PostgreSQL 16, and newer syntax may be rejected.
- The denylist is name-based. A new table that holds secrets is **not** protected until it is added.

## Reviewing the denylist

The default list is not exhaustive. When adding a plugin or upgrading Moodle, check for new tables
or columns that hold secrets. Tables worth checking against the defaults include:

- `enrol.password` (enrolment keys)
- `registration_hubs`, `lti`, `lti_tool_proxies` and `enrol_lti_tools`
- `badge_backpack`, `repository_instances` and `oauth2_server_client_secrets`
- `rest_api_tokens`
- `quiz.password`, `lesson.password` and `quizaccess_seb_quizsettings`

Some of these are covered by the column denylist (`password`, `secret`, `token`). Column coverage is
broad but name-based, so verify before assuming. Do not store a list of "secret tables" only in this
file. Keep it in `DENY_TABLES` and test it.

## Changing these rules

1. State which principle the change touches.
2. Add or update a test that fails without the change (`tests/sql_validator_test.php`).
3. Update this page if a principle is added, relaxed or reworded.
