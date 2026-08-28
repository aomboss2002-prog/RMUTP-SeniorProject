<?php page_header('ไทม์ไลน์', 'ติดตามขั้นตอนอนุมัติและความเคลื่อนไหวของเอกสารโครงงาน', [
    ['label' => 'โครงงาน', 'href' => route_url('projects'), 'icon' => 'fa-diagram-project', 'class' => 'btn btn-outline-primary'],
]); ?>
<div class="card form-card mb-4">
    <label class="form-label" for="timelineProject">โครงงาน</label>
    <select class="form-select" id="timelineProject"></select>
</div>
<div class="card">
    <div class="card-header clean-header"><h2>ไทม์ไลน์โครงงาน</h2></div>
    <div class="card-body timeline" id="projectTimeline"></div>
</div>
