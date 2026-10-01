import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const source = readFileSync('assets/js/student.js', 'utf8');
const context = vm.createContext({});
vm.runInContext(source.slice(source.indexOf('    function studentAcademicStatus'), source.indexOf('    function loadStudentsTable')), context);
for (const value of ['Pending', 'Draft', 'Review', 'Approved', 'Active', 'New', '', null]) {
    assert.equal(context.studentAcademicStatus(value), 'Active');
    assert.equal(context.studentStatusBadge(value, 'export'), 'กำลังศึกษา');
}
assert.equal(context.studentStatusBadge('Completed', 'sort'), 'สำเร็จการศึกษา');
assert.equal(context.studentStatusBadge('Inactive', 'filter'), 'ไม่ใช้งาน');
assert.ok(context.studentStatusBadge('Pending').includes('กำลังศึกษา'));
assert.equal(context.studentStatusBadge('<script>', 'filter'), 'ไม่ระบุ');
const app = readFileSync('assets/js/app.js', 'utf8');
assert.ok(app.includes("Pending: 'รอดำเนินการ'"), 'Project status label must remain unchanged');
console.log('STUDENT_STATUS_OK: academic labels, legacy mapping, export text, project labels preserved');
