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

use sad_spirit\pg_builder\BlankWalker;
use sad_spirit\pg_builder\Lexer;
use sad_spirit\pg_builder\Parser;
use sad_spirit\pg_builder\Select;
use sad_spirit\pg_builder\SelectCommon;
use sad_spirit\pg_builder\SetOpSelect;
use sad_spirit\pg_builder\Values;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\CommonTableExpression;
use sad_spirit\pg_builder\nodes\FunctionCall;
use sad_spirit\pg_builder\nodes\Identifier;
use sad_spirit\pg_builder\nodes\LockingElement;
use sad_spirit\pg_builder\nodes\Star;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\nodes\range\FromElement;
use sad_spirit\pg_builder\nodes\range\JoinExpression;
use sad_spirit\pg_builder\nodes\range\RelationReference;
use sad_spirit\pg_builder\nodes\range\TableSample;

/**
 * PostgreSQL syntax and read-query AST checks, supplementing the shared regex rules.
 *
 * @package   report_sql
 * @copyright 2026 Catalyst IT
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class postgres_validator {
    /**
     * Parse one compiled query without executing it, then check its complete AST.
     *
     * @param string $sql Compiled PostgreSQL SQL, with Moodle placeholders already resolved.
     * @param string[] $deniedtables Lowercase Moodle table names.
     * @param string[] $deniedcolumns Lowercase column names.
     * @throws \moodle_exception When syntax or an AST policy check fails.
     */
    public static function validate(string $sql, array $deniedtables, array $deniedcolumns): void {
        global $CFG;

        require_once($CFG->dirroot . '/report/sql/vendor/autoload.php');

        try {
            $statement = (new Parser(new Lexer()))->parseStatement($sql);
        } catch (\sad_spirit\pg_builder\Exception $e) {
            throw new \moodle_exception('errparse', 'report_sql', '', $e->getMessage());
        }

        if (!$statement instanceof Select && !$statement instanceof SetOpSelect) {
            throw new \moodle_exception('errnotselect', 'report_sql');
        }

        // The external TreeWalker API requires these camelCase callback names.
        // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $walker = new class ($deniedtables, $deniedcolumns, $CFG->prefix) extends BlankWalker {
            /**
             * Store the shared validation policy for this traversal.
             *
             * @param string[] $deniedtables Lowercase Moodle table names.
             * @param string[] $deniedcolumns Lowercase column names.
             * @param string $prefix Site table prefix.
             */
            public function __construct(
                /** @var string[] Lowercase Moodle table names. */
                private array $deniedtables,
                /** @var string[] Lowercase column names. */
                private array $deniedcolumns,
                /** @var string Site table prefix. */
                private string $prefix
            ) {
            }

            /**
             * Reject data-modifying CTEs even when the outer statement is a SELECT.
             *
             * @param CommonTableExpression $node CTE node.
             * @return mixed
             */
            public function walkCommonTableExpression(CommonTableExpression $node): mixed {
                if (!$node->statement instanceof SelectCommon) {
                    throw new \moodle_exception('errnotselect', 'report_sql');
                }
                return parent::walkCommonTableExpression($node);
            }

            /**
             * Visit WITH clauses on VALUES too: the library's default walker omits them.
             *
             * @param Values $statement VALUES statement.
             * @return mixed
             */
            public function walkValuesStatement(Values $statement): mixed {
                $statement->with->dispatch($this);
                return parent::walkValuesStatement($statement);
            }

            /**
             * A report query must not take explicit row locks.
             *
             * @param LockingElement $node Row-lock clause.
             * @return mixed
             */
            public function walkLockingElement(LockingElement $node): mixed {
                throw new \moodle_exception('errdeniedkeyword', 'report_sql', '', 'FOR ' . strtoupper($node->strength));
            }

            /**
             * Reject functions whose hidden reads cannot be checked from this AST.
             *
             * @param FunctionCall $node Function call, including calls in FROM.
             * @return mixed
             */
            public function walkFunctionCall(FunctionCall $node): mixed {
                validator::check_function($node->name->relation->value);
                return parent::walkFunctionCall($node);
            }

            /**
             * Check actual relation nodes, including nested queries and CTEs.
             *
             * @param RelationReference $node Relation node.
             * @return mixed
             */
            public function walkRelationReference(RelationReference $node): mixed {
                $name = $node->name;
                if ($name->schema !== null || $name->catalog !== null) {
                    $parts = [];
                    foreach ([$name->catalog, $name->schema, $name->relation] as $part) {
                        if ($part !== null) {
                            $parts[] = $part->value;
                        }
                    }
                    throw new \moodle_exception('errqualifiedtable', 'report_sql', '', implode('.', $parts));
                }
                $table = strtolower($name->relation->value);
                $prefix = strtolower($this->prefix);
                $unprefixed = $prefix !== '' && str_starts_with($table, $prefix)
                    ? substr($table, strlen($prefix)) : $table;
                foreach ([$table, $unprefixed] as $candidate) {
                    if (in_array($candidate, $this->deniedtables, true)) {
                        throw new \moodle_exception('errdeniedtable', 'report_sql', '', $candidate);
                    }
                }
                return parent::walkRelationReference($node);
            }

            /**
             * Check decoded identifiers, not output aliases or literal text.
             *
             * @param ColumnReference $node Column reference.
             * @return mixed
             */
            public function walkColumnReference(ColumnReference $node): mixed {
                if ($this->deniedcolumns !== []) {
                    if ($node->column instanceof Star && !($node->getParentNode() instanceof TargetElement)) {
                        throw new \moodle_exception('errwholerow', 'report_sql', '', (string) $node);
                    }
                    if ($node->column instanceof Identifier && $node->relation === null) {
                        // PostgreSQL resolves an unqualified range name to its entire composite row.
                        for ($parent = $node->getParentNode(); $parent !== null; $parent = $parent->getParentNode()) {
                            if ($parent instanceof Select) {
                                $ranges = $parent->from;
                            } else if ($parent instanceof JoinExpression) {
                                // A join alias hides its inputs outside the join, but not inside it.
                                $ranges = [$parent->left, $parent->right];
                            } else {
                                continue;
                            }
                            foreach ($ranges as $range) {
                                if (in_array($node->column->value, $this->range_names($range), true)) {
                                    throw new \moodle_exception('errwholerow', 'report_sql', '', (string) $node);
                                }
                            }
                        }
                    }
                }
                if ($node->column instanceof Identifier) {
                    $column = strtolower($node->column->value);
                    if (in_array($column, $this->deniedcolumns, true)) {
                        throw new \moodle_exception('errdeniedcolumn', 'report_sql', '', $column);
                    }
                }
                return parent::walkColumnReference($node);
            }

            /**
             * Names that can denote a whole row in this FROM item, without entering subqueries.
             *
             * @param FromElement $range FROM item.
             * @return string[]
             */
            private function range_names(FromElement $range): array {
                if ($range->tableAlias !== null) {
                    return [$range->tableAlias->value];
                }
                if ($range instanceof TableSample) {
                    return $this->range_names($range->relation);
                }
                if ($range instanceof RelationReference) {
                    return [$range->name->relation->value];
                }
                if ($range instanceof JoinExpression) {
                    $names = array_merge($this->range_names($range->left), $this->range_names($range->right));
                    if ($range->using !== null && $range->using->alias !== null) {
                        $names[] = $range->using->alias->value;
                    }
                    return $names;
                }
                return [];
            }
        };
        // phpcs:enable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $statement->dispatch($walker);
    }
}
