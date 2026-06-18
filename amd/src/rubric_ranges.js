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

/**
 * Modify ranged rubric grading form.
 *
 * @author    Sumaiya Javed <sumaiya.javed@catalyst.net.nz
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    return {
        init: function(gdata) {
            if (gdata) {
                const rows = Object.values(gdata);
                rows.forEach(function(row) {
                    let elementName = "advancedgrading-criteria-" + row.criterionid;
                    let selectlevel = row.avglevel;
                    let elementLevel = document.getElementById(elementName + "-levels-" + selectlevel).classList;
                    let scorevalue = document.getElementById(elementName + "-levels-" + selectlevel + "-score").textContent;
                    let scorevaluerange = scorevalue.split(" to ");
                    if (!(row.avggrade >= Number(scorevaluerange[0]) && row.avggrade <= Number(scorevaluerange[1]))) {
                        selectlevel = selectlevel - 1;
                        elementLevel = document.getElementById(elementName + "-levels-" + selectlevel).classList;
                    }
                    if (elementLevel) {
                        elementLevel.add('currentchecked');
                    }
                    document.getElementById(elementName + "-levels-" + selectlevel + "-definition").checked = true;
                    document.getElementById(elementName + "-grade").value = row.avggrade;
                });
            }
        }
    };
});
