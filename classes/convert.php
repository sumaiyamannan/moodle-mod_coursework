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

namespace mod_coursework;

use mod_coursework\models\coursework;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Class convert_submission to convert submitted files into PDF and rename following the naming a fixed scheme.
 *
 * @package    mod_coursework
 * @copyright  2026 Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class convert {
    /**
     * @var coursework
     */
    protected $coursework;

    /**
     * @var int
     */
    protected $submissionid;

    /**
     * @param coursework $coursework coursework instance
     * @param int $submissionid submission ID
     */
    public function __construct($coursework, $submissionid) {
        $this->coursework = $coursework;
        $this->submissionid = $submissionid;
    }

    /**
     *
     * @param \stdClass $data
     * @return bool
     */
    public function run($data) {
        global $DB;
        $pending  = [];
        $filesonlyrename  = [];
        $submission = $DB->get_record('coursework_submissions', ['id' => $this->submissionid], '*', MUST_EXIST);
        // Load the course module and context, then check capability.
        $cm = get_coursemodule_from_instance('coursework', $submission->courseworkid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        // Retrieve the stored_file record.
        $fs = get_file_storage();
        $converter = new \core_files\converter();
        $count = 0;
        if (isset($data->files)) {
            $files = $data->files;
        } else {
            // Retrieve the file from the submission's file area.
            $files = $fs->get_area_files(
                $context->id,
                'mod_coursework',
                'submission',
                $this->submissionid,
                'timemodified DESC',
                false  // exclude directories
            );
        }
        if (empty($files)) {
            mtrace(get_string('nofilesubmitted', 'mod_coursework'));
            return;
        }
        foreach ($files as $file) {
            $fileid = $file->get_id();
            $count++;
            $user = $DB->get_record('user', ['id' => $submission->authorid], 'firstname, lastname, idnumber');
            $filename = get_string('filerenameprefix', 'mod_coursework', date('Y')) . "_" .$user->idnumber
            ."_" . $user->firstname  ."_" .$user->lastname;
            if (sizeof($files) > 1) {
                $filename = $filename."_" .$count ;
            }
            // If submitted file is PDF then only rename it.
            if ($file->get_mimetype() === 'application/pdf') {
                mtrace("mod_coursework convert_submission: PDF file submitted; only rename file {$fileid}.");
                $this->rename_file($fileid, $filename, $context, $fileid, 'pdf');
                continue;
            }
            // Check the site has at least one working file converter available.
            if (!$converter->can_convert_storedfile_to($file, 'pdf')) {
                mtrace("mod_coursework convert_submission: file cannot be converted; only rename file  {$fileid}.");
                $extension = substr(strrchr($file->get_filename(), '.'), 1);
                $this->rename_file($fileid, $filename, $context, $fileid, $extension);
                continue;
            }
            if (!$file) {
                mtrace("mod_coursework convert_submission: file {$fileid} not found.");
                continue;
            }
            // Start (or resume) the conversion.
            $conversion = $converter->start_conversion($file, 'pdf');
            // Poll once immediately.
            $converter->poll_conversion($conversion);
            switch ($conversion->get('status')) {
                case \core_files\conversion::STATUS_COMPLETE:
                    $destfile = $conversion->get_destfile();
                    $destfileid = $destfile->get_id();
                    // Rename converted file.
                    $this->rename_file($fileid, $filename, $context, $destfileid, 'pdf');
                    break;
                case \core_files\conversion::STATUS_IN_PROGRESS:
                case \core_files\conversion::STATUS_PENDING:
                    // Conversion is asynchronous and not yet done — re-queue this task
                    // to check again after a short delay.
                    mtrace("mod_coursework convert_submission: file {$fileid} conversion still pending, re-queuing.");
                    $this->record_conversion_status($this->submissionid, \core_files\conversion::STATUS_PENDING, $fileid);
                    $pending[] = $file;
                    break;
                case \core_files\conversion::STATUS_FAILED:
                    mtrace("mod_coursework convert_submission: file {$fileid} conversion failed.");
                    $extension = substr(strrchr($file->get_filename(), '.'), 1);
                    $this->rename_file($fileid, $filename, $context, $fileid, $extension);
                    break;
            }
        }
        if (!empty($pending)) {
            $this->requeue((object) [
                'files' => $pending
            ]);
        }
        // Cleanup for this submission.
        $this->delete_orphanfile($files, $context->id);
    }

    /**
     * Rename the file and save in plugin filearea.
     * This follows a custom naming scheme AcademicYear_32_16403_[StudentID]_[FirstName] [LastName].pdf
     *
     * @param int $sourcefileid The source file ID
     * @param string $filename  The filename of the renamed file
     * @param object $context   The context of the file
     * @param int $destfileid   The destinate file ID
     * @param string $extension   The file extension
     */
    private function rename_file($sourcefileid, $filename, $context, $destfileid, $extension) {
        global $DB;
        $fs = get_file_storage();
        $destfile = $fs->get_file_by_id($destfileid);
        $filename = $filename.".".$extension;
        // Copy into plugin file area.
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_coursework',
            'filearea'  => 'convertedpdf',
            'itemid'    => $this->submissionid,
            'filepath'  => '/',
            'filename'  => $filename,
            'referencefileid'  => $sourcefileid,
        ];
        // Avoid duplicates if the task ever re-runs.
        $existing = $fs->get_file(...array_values($filerecord));
        if ($existing) {
            $DB->delete_records('coursework_submissions_conversion', [
                'submissionid' => $this->submissionid, 'fileid' => $existing->get_id()
            ]);
            $existing->delete();
        }

        $ownfile = $fs->create_file_from_storedfile($filerecord, $destfile);
        $url = \moodle_url::make_pluginfile_url(
            $ownfile->get_contextid(),
            $ownfile->get_component(),
            $ownfile->get_filearea(),
            $ownfile->get_itemid(),
            $ownfile->get_filepath(),
            $ownfile->get_filename()
        );
        mtrace("mod_coursework convert_submission: file {$sourcefileid} processed successfully,
         destfile id = {$ownfile->get_id()}.");
        mtrace("URL {$url} to download processed file");
        $this->record_conversion_status($this->submissionid, \core_files\conversion::STATUS_COMPLETE, $ownfile->get_id());
    }
    /**
     * Delete orphan records from old submissions.
     *
     * @param object $files  Submission file records
     * @param int $contextid   The context ID of the submission
     */
    private function delete_orphanfile($files, $contextid) {
        global $DB;
        $fileids = [];
        foreach ($files as $file) {
            $fileids[] = $file->get_id();
        }
        $fs = get_file_storage();
        // Get converted files for this submission.
        $convertedfiles = $fs->get_area_files(
            $contextid,
            'mod_coursework',
            'convertedpdf',
            $this->submissionid,
            "id",
            false  // exclude directories
        );
        if ($convertedfiles) {
            foreach ($convertedfiles as $cfile) {
                if (!in_array($cfile->get_referencefileid(), $fileids)) {
                    $DB->delete_records('coursework_submissions_conversion', [
                        'submissionid' => $cfile->get_itemid(), 'fileid' => $cfile->get_id()
                    ]);
                    mtrace("mod_coursework orphan record delete: file {$cfile->get_id()}.");
                    $cfile->delete();

                }
            }
        }
    }

    /**
     * Record converstion status in plugin table.
     *
     * @param int        $submissionid  Submission record id
     * @param int        $status        Status of the submission conversion
     * @param int        $fileid        The file ID of the converted file
     */
    private function record_conversion_status(int $submissionid, int $status, int $fileid = 0): void {
        global $DB;
        $table = 'coursework_submissions_conversion';
        $params = ['submissionid' => $submissionid, 'fileid' => $fileid];
        if ($DB->record_exists($table, $params)) {
            $DB->set_field($table, 'status', $status, $params);
        } else {
            $DB->insert_record($table, ['submissionid' => $submissionid, 'fileid' => $fileid,
             'status' => $status, 'timecreated' => time()]);
        }
    }

    /**
     * Queue an adhoc task to convert a submitted file to PDF.
     *
     * @param int $userid The submission creator
     */
    public function queue(int $userid): void {
        $task = new \mod_coursework\task\convert_submission();
        $data = new stdClass();
        $data->coursework = $this->coursework;
        $data->submissionid = $this->submissionid;
        $task->set_userid($userid);
        $task->set_custom_data($data);
        \core\task\manager::queue_adhoc_task($task, true); // true = skip if identical task already queued
    }

    /**
     * Re-queue this task to retry the conversion after a short delay.
     * Only retries up to a maximum number of attempts.
     */
    public function requeue(\stdClass $data): void {
        $task = new \mod_coursework\task\convert_submission();
        $data = new stdClass();
        $data->coursework = $this->coursework;
        $data->submissionid = $this->submissionid;
        $data->fileids = $data->fileids;
        $task->set_custom_data($data);
        $task->set_next_run_time(\core\di::get(\core\clock::class)->time() + MINSECS);
        \core\task\manager::queue_adhoc_task($task, true);
    }

}
