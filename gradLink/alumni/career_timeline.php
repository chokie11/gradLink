<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['alumni']);

$user_id = $_SESSION['user_id'];

$responsesStmt = $pdo->prepare("\n    SELECT r.*, s.title AS survey_title, s.version AS survey_current_version\n    FROM tracer_responses r\n    INNER JOIN tracer_surveys s ON s.id = r.survey_id\n    WHERE r.alumni_id = ?\n      AND s.survey_type = 'tracer'\n    ORDER BY r.submitted_at DESC, r.id DESC\n");
$responsesStmt->execute([$user_id]);
$responses = $responsesStmt->fetchAll(PDO::FETCH_ASSOC);

$responseAnswers = [];
if (!empty($responses)) {
    $answerStmt = $pdo->prepare("\n        SELECT q.question_text, q.question_type, a.answer_text\n        FROM tracer_answers a\n        INNER JOIN tracer_questions q ON q.id = a.question_id\n        WHERE a.response_id = ?\n        ORDER BY q.sort_order ASC, q.id ASC\n    ");

    foreach ($responses as $response) {
        $answerStmt->execute([$response['id']]);
        $responseAnswers[$response['id']] = $answerStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Career Timeline';
$pageSubtitle = 'View your tracer survey history and career progress.';
$activePage = 'surveys';

require_once '../includes/dashboard_header.php';
?>

<div class="panel-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <div class="panel-title mb-1">Career Timeline</div>
            <div class="text-muted">Your tracer survey submissions are saved as career snapshots.</div>
        </div>
        <a href="update_career.php" class="btn btn-maroon">
            <?= empty($responses) ? 'Take Tracer Survey' : 'Update Career Status' ?>
        </a>
    </div>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success rounded-4">
            Your career update has been saved successfully.
        </div>
    <?php endif; ?>

    <?php if (empty($responses)): ?>
        <div class="section-box mt-0 text-center">
            <div class="section-title">No Career Timeline Yet</div>
            <p class="text-muted mb-3">
                You have not submitted your tracer survey yet. Submit your first tracer survey to start your career timeline.
            </p>
            <a href="update_career.php" class="btn btn-maroon">Take Tracer Survey</a>
        </div>
    <?php else: ?>
        <?php foreach ($responses as $index => $response): ?>
            <?php $answers = $responseAnswers[$response['id']] ?? []; ?>

            <div class="section-box <?= $index === 0 ? 'mt-0' : '' ?>">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="section-title mb-1">
                            <?= ((int)$response['is_latest'] === 1) ? 'Current Career Status' : 'Previous Career Update' ?>
                        </div>
                        <div class="text-muted">
                            Submitted on <?= date('F d, Y h:i A', strtotime($response['submitted_at'])) ?>
                        </div>
                        <div class="text-muted small">
                            <?= htmlspecialchars($response['survey_title']) ?>
                            <?php if (!empty($response['survey_version'])): ?>
                                · Version <?= htmlspecialchars($response['survey_version']) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ((int)$response['is_latest'] === 1): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2">Latest</span>
                    <?php else: ?>
                        <span class="badge bg-secondary rounded-pill px-3 py-2">History</span>
                    <?php endif; ?>
                </div>

                <?php if (empty($answers)): ?>
                    <div class="text-muted">No answers found for this update.</div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($answers as $answer): ?>
                            <div class="col-md-6">
                                <div class="border rounded-4 p-3 h-100">
                                    <div class="small text-muted mb-1">
                                        <?= htmlspecialchars($answer['question_text']) ?>
                                    </div>
                                    <div class="fw-semibold">
                                        <?= $answer['answer_text'] !== null && trim($answer['answer_text']) !== ''
                                            ? nl2br(htmlspecialchars($answer['answer_text']))
                                            : '<span class="text-muted">No answer</span>' ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once '../includes/dashboard_footer.php'; ?>
