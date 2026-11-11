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

use report_sql\local\sql\mysql_validator;
use report_sql\local\sql\validator;

/**
 * MySQL-family policy checks without executing SQL.
 *
 * @package   report_sql
 * @copyright 2026 Catalyst IT
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_sql\local\sql\mysql_validator
 */
final class mysql_validator_test extends \advanced_testcase {
    /**
     * Queries and expected policy outcomes, not syntax-validation expectations.
     *
     * @return array
     */
    public static function policy_provider(): array {
        return [
            'simple' => ['SELECT id FROM {course}', null],
            'literal comment markers' => ["SELECT '/* config password */' AS label", null],
            'comment' => ['SELECT 1 /* LOAD_FILE() FOR SHARE := */ AS n', null],
            'optimizer hint' => ['SELECT /*+ MAX_EXECUTION_TIME(1000) */ id FROM {course}', null],
            'cte' => ['WITH x AS (SELECT id FROM {course}) SELECT id FROM x', null],
            'window' => ['SELECT ROW_NUMBER() OVER (ORDER BY id) AS n FROM {course}', null],
            'alias is not source column' => ['SELECT id AS password FROM {course}', null],
            'literal executable marker' => ["SELECT '/*! */ /*M! */' AS label", null],
            'denied table' => ['SELECT value FROM {config}', 'errdeniedtable'],
            'nested denied table' => [
                "SELECT '/*' AS a, (SELECT value FROM {config} LIMIT 1) AS b, '*/' AS c FROM {user}", 'errdeniedtable',
            ],
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Test MySQL identifier quoting.
            'quoted table' => ['SELECT * FROM `{prefix}config`', 'errdeniedtable'],
            'cte table' => ['WITH x AS (SELECT value FROM {config}) SELECT * FROM x', 'errdeniedtable'],
            'union table' => ['SELECT id FROM {course} UNION SELECT id FROM {config}', 'errdeniedtable'],
            'nested column' => ['SELECT (SELECT password AS pw FROM {user}) AS b', 'errdeniedcolumn'],
            'cte column' => ['WITH x AS (SELECT password AS pw FROM {user}) SELECT pw FROM x', 'errdeniedcolumn'],
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Test MySQL identifier quoting.
            'quoted column' => ['SELECT u.`password` AS pw FROM {user} u', 'errdeniedcolumn'],
            'schema' => ['SELECT * FROM information_schema.tables', 'errqualifiedtable'],
            'nested schema' => [
                'SELECT (SELECT table_name FROM information_schema.tables LIMIT 1) AS n', 'errqualifiedtable',
            ],
            'load file' => ["SELECT LOAD_FILE('/not-a-real-file') AS x", 'errdeniedfunction'],
            'nested function' => ["SELECT COALESCE(LOAD_FILE('/not-a-real-file'), '') AS x", 'errdeniedfunction'],
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Test MySQL identifier quoting.
            'quoted function' => ["SELECT `load_file`('/not-a-real-file') AS x", 'errdeniedfunction'],
            'qualified function' => ["SELECT mysql.load_file('/not-a-real-file') AS x", 'errdeniedfunction'],
            'dynamic query function' => ["SELECT query_to_xml('select 1', true, true, '')", 'errdeniedfunction'],
            'mysql executable comment' => ['SELECT 1 /*!50000 + 1 */ AS n', 'errmysqlexecutablecomment'],
            'mariadb executable comment' => ['SELECT 1 /*M!100000 + 1 */ AS n', 'errmysqlexecutablecomment'],
            'update lock' => ['SELECT id FROM {course} FOR UPDATE', 'errdeniedkeyword'],
            'share lock' => ['SELECT id FROM {course} FOR SHARE', 'errdeniedkeyword'],
            'legacy share lock' => ['SELECT id FROM {course} LOCK IN SHARE MODE', 'errdeniedkeyword'],
            'nested lock' => ['SELECT * FROM (SELECT id FROM {course} FOR SHARE) x', 'errdeniedkeyword'],
            'assignment' => ['SELECT @x := 1 AS n', 'errdeniedkeyword'],
            'insert statement' => ['INSERT INTO {course} (id) VALUES (1)', 'errnotselect'],
            'update statement' => ['UPDATE {course} SET id = 1', 'errnotselect'],
            'delete statement' => ['DELETE FROM {course}', 'errnotselect'],
        ];
    }

    /**
     * Exercise the recursive policy directly on any Moodle database.
     *
     * @dataProvider policy_provider
     * @param string $sql Query with Moodle table/prefix placeholders.
     * @param string|null $errorcode Expected policy error or null.
     */
    public function test_policy(string $sql, ?string $errorcode): void {
        global $CFG;

        require_once($CFG->dirroot . '/report/sql/vendor/autoload.php');
        $sql = str_replace('{prefix}', $CFG->prefix, $sql);
        $sql = preg_replace_callback('/\{(\w+)\}/', static fn(array $m): string => $CFG->prefix . $m[1], $sql);
        $tree = (new \PHPSQLParser\PHPSQLParser($sql))->parsed;
        try {
            mysql_validator::validate($tree, $sql, ['config'], ['password']);
            $this->assertNull($errorcode, 'Expected SQL to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The shared entry point routes MySQL-family databases to this policy.
     */
    public function test_mysql_entry_point(): void {
        global $DB;

        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MySQL-family validator routing');
        }
        $sql = 'SELECT %%TIMESTAMP(timecreated)%% AS created FROM {course}';
        $this->assertSame($sql, validator::validate($sql));
        $this->expectException(\moodle_exception::class);
        validator::validate("SELECT LOAD_FILE('/not-a-real-file') AS contents");
    }
}
