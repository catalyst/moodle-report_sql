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

/**
 * PostgreSQL AST checks, runnable on either database family without executing SQL.
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
