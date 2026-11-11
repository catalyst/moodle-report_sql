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

namespace report_sql\local\sql;

/**
 * Recursive Greenlion policy checks for MySQL, MariaDB and Aurora MySQL.
 *
 * This is not a syntax validator; the live database check remains required.
 *
 * @package   report_sql
 * @copyright 2026 Catalyst IT
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mysql_validator {
    /**
     * Inspect compiled SQL and every node of its Greenlion tree.
     *
     * @param array $tree Greenlion parse tree.
     * @param string $sql Compiled SQL.
     * @param string[] $deniedtables Lowercase Moodle table names.
     * @param string[] $deniedcolumns Lowercase column names.
     */
    public static function validate(array $tree, string $sql, array $deniedtables, array $deniedcolumns): void {
        global $CFG;

        // Match literals before comments so comment markers inside values cannot hide SQL.
        $pattern = '~\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"'
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Backticks delimit MySQL identifiers.
            . '|`(?:``|[^`])*`|/\*.*?(?:\*/|$)|\#[^\r\n]*|--(?=\s|$)[^\r\n]*~s';
        $code = preg_replace_callback($pattern, static function (array $match): string {
            $token = $match[0];
            if (preg_match('~^/\*(?:!|M!)~i', $token)) {
                throw new \moodle_exception('errmysqlexecutablecomment', 'report_sql');
            }
            return ' ';
        }, $sql);
        if ($code === null) {
            throw new \coding_exception('Failed to tokenize MySQL SQL for validation');
        }
        if (preg_match('/\bFOR\s+(?:UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $code, $match)) {
            throw new \moodle_exception('errdeniedkeyword', 'report_sql', '', $match[0]);
        }
        if (str_contains($code, ':=')) {
            throw new \moodle_exception('errdeniedkeyword', 'report_sql', '', ':=');
        }
        self::walk($tree, $deniedtables, $deniedcolumns, strtolower($CFG->prefix));
    }

    /**
     * Inspect statement subtrees and decoded source identifiers, ignoring aliases.
     *
     * @param array $node Tree or subtree.
     * @param string[] $deniedtables Table policy.
     * @param string[] $deniedcolumns Column policy.
     * @param string $prefix Site prefix.
     */
    private static function walk(array $node, array $deniedtables, array $deniedcolumns, string $prefix): void {
        if (array_intersect(['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'DROP', 'SET', 'INTO'], array_keys($node))) {
            throw new \moodle_exception('errnotselect', 'report_sql');
        }
        if (isset($node['SELECT']) || isset($node['WITH'])) {
            $allowed = [
                'SELECT', 'FROM', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET',
                'WITH', 'UNION', 'UNION ALL', 'EXCEPT', 'INTERSECT', 'BRACKET', 'OPTIONS',
            ];
            foreach (array_keys($node) as $key) {
                if (!in_array(strtoupper((string) $key), $allowed, true)) {
                    throw new \moodle_exception('errnotselect', 'report_sql');
                }
            }
        }
        $type = $node['expr_type'] ?? '';
        if ($type === 'table' && isset($node['table'])) {
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Decode MySQL identifier delimiters.
            $parts = $node['no_quotes']['parts'] ?? [trim($node['table'], '`"')];
            if (count($parts) > 1) {
                throw new \moodle_exception('errqualifiedtable', 'report_sql', '', $node['table']);
            }
            $table = strtolower($parts[0]);
            $bare = $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
            foreach ([$table, $bare] as $candidate) {
                if (in_array($candidate, $deniedtables, true)) {
                    throw new \moodle_exception('errdeniedtable', 'report_sql', '', $candidate);
                }
            }
            if (str_contains($node['base_expr'] ?? '', '(')) {
                self::check_function($node['table']);
            }
        } else if ($type === 'colref') {
            $parts = $node['no_quotes']['parts'] ?? [$node['base_expr']];
            $column = strtolower((string) end($parts));
            if (in_array($column, $deniedcolumns, true)) {
                throw new \moodle_exception('errdeniedcolumn', 'report_sql', '', $column);
            }
        } else if (in_array($type, ['function', 'custom_function'], true)) {
            self::check_function($node['base_expr']);
        }
        foreach ($node as $key => $child) {
            if ($key !== 'alias' && is_array($child)) {
                self::walk($child, $deniedtables, $deniedcolumns, $prefix);
            }
        }
    }

    /**
     * Check functions independently of quoted or schema-qualified spelling.
     *
     * @param string $name Function name.
     */
    private static function check_function(string $name): void {
        $parts = explode('.', $name);
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Decode MySQL identifier delimiters.
        $name = trim(end($parts), "`\" \t\r\n");
        if (strtolower($name) === 'load_file') {
            throw new \moodle_exception('errdeniedfunction', 'report_sql', '', $name);
        }
        validator::check_function($name);
    }
}
