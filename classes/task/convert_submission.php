<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_coursework\task;

/**
 * Class convert_submission
 *
 * @package    mod_coursework
 * @copyright  2026 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adhoc task to convert a submitted file to PDF
 * using the core file converter API.
 */
class convert_submission extends \core\task\adhoc_task {

    /**
     * Convert submission.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_convert_submission', 'mod_coursework');
    }

    /**
     * Run conversion.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $convert = new \mod_coursework\convert($data->coursework, $data->submissionid);
        $convert->run($data);
    }
}