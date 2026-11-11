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

namespace report_sql\local;

/**
 * Helper to use the read-only database connection when available.
 *
 * Wraps calls to attempt using a read-only connection if one is configured.
 * Falls back to the default connection if the feature is not yet available
 * or if read-only is not configured.
 *
 * @package   report_sql
 * @copyright 2026 Catalyst IT
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class db_reader {
    /**
     * Get the appropriate database connection for read operations.
     *
     * Attempts to use the read-only connection if available via
     * $DB->has_readonly_connection(), falling back to the default connection
     * if the feature is not available or if no read-only connection is configured.
     *
     * @return \moodle_database The read-only connection if available, otherwise the default connection.
     */
    public static function get(): \moodle_database {
        global $DB;

        // Try to use the read-only connection if available (when the tracker lands).
        // Use has_readonly_connection() to check first, then get_readonly_connection() to fetch it.
        if (method_exists($DB, 'has_readonly_connection') && $DB->has_readonly_connection()) {
            try {
                return $DB->get_readonly_connection();
            } catch (\Exception $e) {
                // Fall back to the default connection if the read-only connection fails.
                // In future versions, log a warning here for admins as well.
                debugging('report_sql: failed to get read-only database connection: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Fall back to the default connection.
        return $DB;
    }
}
