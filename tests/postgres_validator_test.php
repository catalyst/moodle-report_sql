<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace report_sql;

use report_sql\local\sql\postgres_validator;
use report_sql\local\sql\validator;
use report_sql\local\sql\view;

/**
 * PostgreSQL AST checks on either database family, plus PostgreSQL integration coverage.
 *
 * @package   report_sql
 * @copyright 2026 Catalyst IT
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_sql\local\sql\postgres_validator
 */
final class postgres_validator_test extends \advanced_testcase {
    /**
     * Valid PostgreSQL syntax that does not require table or function resolution.
     *
     * @return array
     */
    public static function valid_provider(): array {
        return [
            'cast' => ['SELECT 1::integer AS n'],
            'distinct on' => ['SELECT DISTINCT ON (id) id FROM course ORDER BY id'],
            'filter' => ['SELECT COUNT(*) FILTER (WHERE id > 1) AS n FROM course'],
            'window' => ['SELECT ROW_NUMBER() OVER (PARTITION BY category ORDER BY id) AS n FROM course'],
            'lateral' => ['SELECT c.id FROM course c CROSS JOIN LATERAL (SELECT c.id) x'],
            'natural join' => ['SELECT c.id FROM course c NATURAL JOIN course_categories'],
            'using join' => ['SELECT id FROM course JOIN course_categories USING (id)'],
            'recursive cte' => [
                'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 3) SELECT i FROM n',
            ],
            'materialized cte' => ['WITH x AS MATERIALIZED (SELECT 1 AS id) SELECT id FROM x'],
            'values cte' => ['WITH x(id) AS (VALUES (1), (2)) SELECT id FROM x'],
            'set operations' => ['SELECT 1 UNION ALL SELECT 2 INTERSECT SELECT 3 EXCEPT SELECT 4'],
            'array' => ['SELECT ARRAY[1, 2]::integer[] AS a'],
            'json' => ['SELECT \'{"id": 1}\'::jsonb ->> \'id\' AS id'],
            'dollar literal' => ['SELECT $$DELETE FROM config;$$ AS label'],
            'escape literal' => ["SELECT E'it\\'s a string' AS label"],
            'denied words in literal' => ["SELECT 'password config' AS label"],
            'nested comments' => ['SELECT /* outer /* nested */ outer */ 1'],
            'function schema is not a table' => ['SELECT pg_catalog.lower(\'TEXT\') AS label'],
            'wildcard projection' => ['SELECT * FROM users'],
            'qualified wildcard projection' => ['SELECT u.* FROM users u'],
            'nested wildcard projection' => ['SELECT * FROM (SELECT * FROM users) u'],
            'count wildcard' => ['SELECT COUNT(*) AS n FROM users'],
            'explicit JSON row' => ['SELECT row_to_json(ROW(u.id, u.username)) AS data FROM users u'],
            'JSON scalar' => ['SELECT to_jsonb(u.username) AS data FROM users u'],
            'qualified column matching alias' => ['SELECT u.u FROM users u'],
            'unrelated subquery alias' => ['SELECT u FROM users WHERE id IN (SELECT u.id FROM users u)'],
            'quoted alias case differs' => ['SELECT u FROM users "U"'],
        ];
    }

    /**
     * The AST parser understands PostgreSQL-specific syntax.
     *
     * @dataProvider valid_provider
     * @param string $sql Compiled SQL.
     */
    public function test_valid(string $sql): void {
        postgres_validator::validate($sql, ['config'], ['password']);
        $this->addToAssertionCount(1);
    }

    /**
     * AST rejections independent of the shared regex checks.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'incomplete expression' => ['SELECT 1 +', 'errparse'],
            'missing join condition' => ['SELECT c.id FROM course c JOIN course_categories cc', 'errparse'],
            'nested missing join' => ['SELECT * FROM (SELECT * FROM course JOIN course_categories) x', 'errparse'],
            'multiple statements' => ['SELECT 1; SELECT 2', 'errparse'],
            'unseparated statements' => ['SELECT 1 SELECT 2', 'errparse'],
            'trailing garbage' => ['SELECT 1 FROM course WHERE', 'errparse'],
            'mysql limit' => ['SELECT id FROM course LIMIT 0, 10', 'errparse'],
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Reject MySQL identifier quoting on PostgreSQL.
            'mysql backticks' => ['SELECT `id` FROM course', 'errparse'],
            'root insert' => ['INSERT INTO course (id) VALUES (1)', 'errnotselect'],
            'root values' => ['VALUES (1)', 'errnotselect'],
            'delete cte' => ['WITH x AS (DELETE FROM course RETURNING id) SELECT id FROM x', 'errnotselect'],
            'update cte' => ['WITH x AS (UPDATE course SET id = 1 RETURNING id) SELECT id FROM x', 'errnotselect'],
            'insert cte' => ['WITH x AS (INSERT INTO course (id) VALUES (1) RETURNING id) SELECT id FROM x', 'errnotselect'],
            'newer merge cte syntax' => [
                'WITH x AS (MERGE INTO course c USING course_categories cc ON c.id = cc.id '
                    . 'WHEN MATCHED THEN DELETE RETURNING c.id) SELECT id FROM x', 'errparse',
            ],
            'merge statement' => [
                'MERGE INTO course c USING course_categories cc ON c.id = cc.id '
                    . 'WHEN MATCHED THEN DELETE', 'errnotselect',
            ],
            'nested modifying cte' => [
                'SELECT * FROM (WITH x AS (DELETE FROM course RETURNING id) SELECT id FROM x) s', 'errnotselect',
            ],
            'values with modifying cte' => [
                'WITH x AS (WITH y AS (DELETE FROM course RETURNING id) VALUES (1)) SELECT * FROM x', 'errnotselect',
            ],
            'schema table' => ['SELECT * FROM information_schema.columns', 'errqualifiedtable'],
            'schema in union' => ['SELECT 1 UNION SELECT id FROM public.course', 'errqualifiedtable'],
            'schema in cte' => ['WITH x AS (SELECT * FROM public.course) SELECT * FROM x', 'errqualifiedtable'],
            'schema in values cte' => [
                'WITH x AS (WITH y AS (SELECT * FROM public.course) VALUES (1)) SELECT * FROM x', 'errqualifiedtable',
            ],
            'quoted schema' => ['SELECT * FROM "public"."course"', 'errqualifiedtable'],
            'denied table' => ['SELECT * FROM config', 'errdeniedtable'],
            'denied prefixed table' => ['SELECT * FROM "{prefix}config"', 'errdeniedtable'],
            'denied nested table' => ['SELECT * FROM (SELECT * FROM "{prefix}config") x', 'errdeniedtable'],
            'denied column alias' => ['SELECT password AS pw FROM course', 'errdeniedcolumn'],
            'denied nested column' => ['SELECT (SELECT password FROM course) AS pw', 'errdeniedcolumn'],
            'denied quoted column' => ['SELECT "password" AS pw FROM course', 'errdeniedcolumn'],
            'JSON whole row' => ['SELECT row_to_json(u) AS data FROM users u', 'errwholerow'],
            'JSONB whole row' => ['SELECT to_jsonb(u) AS data FROM users u', 'errwholerow'],
            'qualified JSON function whole row' => ['SELECT pg_catalog.row_to_json(u) FROM users u', 'errwholerow'],
            'JSON expanded row' => ['SELECT row_to_json(u.*) FROM users u', 'errwholerow'],
            'JSONB expanded row' => ['SELECT to_jsonb(u.*) FROM users u', 'errwholerow'],
            'row constructor wildcard' => ['SELECT ROW(u.*) FROM users u', 'errwholerow'],
            'whole row cast' => ['SELECT u::text FROM users u', 'errwholerow'],
            'whole row projection' => ['SELECT u FROM users u', 'errwholerow'],
            'unaliased whole row' => ['SELECT row_to_json(users) FROM users', 'errwholerow'],
            'quoted whole row' => ['SELECT row_to_json("U") FROM users "U"', 'errwholerow'],
            'nested whole row' => ['SELECT (SELECT row_to_json(u)) FROM users u', 'errwholerow'],
            'lateral whole row' => [
                'SELECT x.data FROM users u CROSS JOIN LATERAL (SELECT to_jsonb(u) AS data) x', 'errwholerow',
            ],
            'lateral inside aliased join' => [
                'SELECT j.data FROM (users u CROSS JOIN LATERAL (SELECT to_jsonb(u) AS data) x) j', 'errwholerow',
            ],
            'joined whole row' => [
                'SELECT row_to_json(u) FROM users u JOIN course c ON c.id = u.id', 'errwholerow',
            ],
            'join alias whole row' => [
                'SELECT row_to_json(j) FROM (users u JOIN course c ON c.id = u.id) j', 'errwholerow',
            ],
            'whole row in aliased join condition' => [
                "SELECT j.id FROM (users u JOIN course c ON to_jsonb(u) = '{}'::jsonb) j", 'errwholerow',
            ],
            'join using alias whole row' => [
                'SELECT row_to_json(j) FROM users u JOIN course c USING (id) AS j', 'errwholerow',
            ],
            'sampled whole row' => [
                'SELECT row_to_json(users) FROM users TABLESAMPLE SYSTEM (1)', 'errwholerow',
            ],
            'derived whole row' => ['SELECT row_to_json(u) FROM (SELECT * FROM users) u', 'errwholerow'],
            'CTE whole row' => [
                'WITH u AS (SELECT * FROM users) SELECT to_jsonb(u) FROM u', 'errwholerow',
            ],
            'union whole row' => [
                'SELECT 1::text UNION ALL SELECT u::text FROM users u', 'errwholerow',
            ],
            'row lock share' => ['SELECT id FROM course FOR SHARE', 'errdeniedkeyword'],
            'row lock update' => ['SELECT id FROM course FOR UPDATE', 'errdeniedkeyword'],
            'row lock key share' => ['SELECT id FROM course FOR KEY SHARE', 'errdeniedkeyword'],
            'row lock no key update' => ['SELECT id FROM course FOR NO KEY UPDATE', 'errdeniedkeyword'],
            'nested row lock' => ['SELECT * FROM (SELECT id FROM course FOR SHARE) x', 'errdeniedkeyword'],
            'dynamic SQL function' => ["SELECT query_to_xml('select 1', true, true, '')", 'errdeniedfunction'],
            'qualified XML function' => [
                "SELECT pg_catalog.\"query_to_xml\"('select 1', true, true, '')", 'errdeniedfunction',
            ],
            'nested XML function' => [
                "SELECT (SELECT query_to_xml('select 1', true, true, ''))", 'errdeniedfunction',
            ],
            'FROM XML function' => [
                "SELECT * FROM query_to_xml('select 1', true, true, '')", 'errdeniedfunction',
            ],
        ];
    }

    /**
     * Invalid syntax and forbidden AST nodes fail explicitly.
     *
     * @dataProvider invalid_provider
     * @param string $sql Compiled SQL with an optional site prefix placeholder.
     * @param string $errorcode Expected Moodle error.
     */
    public function test_invalid(string $sql, string $errorcode): void {
        global $CFG;

        try {
            postgres_validator::validate(str_replace('{prefix}', $CFG->prefix, $sql), ['config'], ['password']);
            $this->fail('Expected SQL to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * Whole rows are permitted when the administrator has disabled column restrictions.
     */
    public function test_whole_rows_without_column_denylist(): void {
        postgres_validator::validate('SELECT row_to_json(u), to_jsonb(u.*) FROM users u', ['config'], []);
        $this->addToAssertionCount(1);
    }

    /**
     * Ordinary wildcard reports retain output filtering, and explicitly constructed JSON still runs.
     */
    public function test_postgres_wildcard_columns_and_explicit_json(): void {
        global $DB, $USER;

        if ($DB->get_dbfamily() !== 'postgres') {
            $this->markTestSkipped('PostgreSQL-specific report expressions');
        }

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('denycolumns', 'password', 'report_sql');
        $user = $this->getDataGenerator()->create_user();

        foreach (['*', 'u.*'] as $projection) {
            $sql = validator::validate('SELECT ' . $projection . ' FROM {user} u');
            $viewname = view::create_or_replace_preview((int) $USER->id, $sql);
            try {
                $columns = view::columns($viewname);
                $this->assertArrayHasKey('id', $columns);
                $this->assertArrayHasKey('username', $columns);
                $this->assertArrayNotHasKey('password', $columns);
            } finally {
                view::drop_preview((int) $USER->id);
            }
        }

        $sql = validator::validate('SELECT row_to_json(ROW(u.id, u.username)) AS data FROM {user} u');
        $json = $DB->get_field_sql(view::compile($sql) . ' WHERE u.id = ?', [$user->id]);
        $this->assertSame(
            ['f1' => (int) $user->id, 'f2' => $user->username],
            json_decode($json, true, 512, JSON_THROW_ON_ERROR)
        );

        foreach (['row_to_json(u)', 'to_jsonb(u)', 'row_to_json(u.*)', 'to_jsonb(u.*)'] as $expression) {
            $sql = 'SELECT ' . $expression . ' AS data FROM {user} u';
            $json = $DB->get_field_sql(view::compile($sql) . ' WHERE u.id = ?', [$user->id]);
            $this->assertArrayHasKey('password', json_decode($json, true, 512, JSON_THROW_ON_ERROR));
            try {
                validator::validate($sql);
                $this->fail('Expected whole-row SQL to be rejected');
            } catch (\moodle_exception $e) {
                $this->assertSame('errwholerow', $e->errorcode);
            }
        }
    }

    /**
     * The shared entry point compiles tokens and returns the original portable SQL.
     */
    public function test_postgres_entry_point(): void {
        global $DB;

        if ($DB->get_dbfamily() !== 'postgres') {
            $this->markTestSkipped('PostgreSQL-specific validator routing');
        }

        $sql = "SELECT %%TIMESTAMP(timecreated)%% AS created, %%CASE(fullname, title)%% AS name, "
            . "%%NOW%% AS nowtime, %%EPOCH('2020-01-01')%% AS epoch, "
            . "%%GROUP_CONCAT(shortname, ', ')%% OVER () AS names, "
            . "%%VIEWER(id)%% AS viewer, %%TEACHES(id)%% AS teacher, %%PAGECOURSE(id)%% AS pagecourse, "
            . "%%LINK(id, '/course/view.php?id={}')%% AS link, %%COURSEID%% AS courseid, "
            . "%%COURSECONTEXT%% AS contextid, %%CONTEXT_COURSE%% AS contextlevel, '%%WWWROOT%%' AS site "
            . 'FROM {course}';
        $this->assertSame($sql, validator::validate($sql));
        $this->assertSame('SELECT 1::integer AS n', validator::validate('SELECT 1::integer AS n'));
        $this->expectException(\moodle_exception::class);
        validator::validate('SELECT 1 +');
    }
}
