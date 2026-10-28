<?php
require_once '../includes/auth_check.php';
require_once '../includes/role_check.php';
require_once '../config/database.php';

requireRole(['admin']);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$industryFilter = trim($_GET['industry'] ?? '');
$completionFilter = trim($_GET['completion'] ?? '');

function partnerProfileCompletion(array $row): int {
    $fields = [
        $row['company_name'] ?? '',
        $row['industry_type'] ?? '',
        $row['contact_person'] ?? '',
        $row['phone'] ?? '',
        $row['address'] ?? '',
        $row['website'] ?? '',
        $row['company_description'] ?? ''
    ];

    $filled = 0;
    foreach ($fields as $field) {
        if (!empty(trim((string) $field))) {
            $filled++;
        }
    }

    return count($fields) > 0 ? (int) round(($filled / count($fields)) * 100) : 0;
}

function statusBadgeClass(string $status): string {
    return match (strtolower($status)) {
        'active' => 'partner-status partner-status-active',
        'inactive' => 'partner-status partner-status-inactive',
        'pending' => 'partner-status partner-status-pending',
        default => 'partner-status partner-status-neutral'
    };
}

function completionTone(int $completion): string {
    if ($completion >= 100) {
        return 'Complete';
    }
    if ($completion >= 70) {
        return 'Good';
    }
    if ($completion >= 40) {
        return 'Needs Update';
    }
    return 'Incomplete';
}

function keepQuery(array $extra = []): string {
    $query = array_merge($_GET, $extra);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return http_build_query($query);
}

$baseSql = "
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.status,
        u.created_at,
        pp.company_name,
        pp.industry_type,
        pp.contact_person,
        pp.phone,
        pp.address,
        pp.website,
        pp.company_description
    FROM users u
    LEFT JOIN partner_profiles pp ON u.id = pp.user_id
    WHERE u.role = 'partner'
";

$params = [];

if ($search !== '') {
    $baseSql .= " AND (
        u.first_name LIKE ?
        OR u.last_name LIKE ?
        OR u.email LIKE ?
        OR pp.company_name LIKE ?
        OR pp.industry_type LIKE ?
        OR pp.contact_person LIKE ?
        OR pp.phone LIKE ?
        OR pp.website LIKE ?
    )";

    $searchLike = '%' . $search . '%';
    $params = array_fill(0, 8, $searchLike);
}

if ($statusFilter !== '') {
    $baseSql .= " AND u.status = ?";
    $params[] = $statusFilter;
}

if ($industryFilter !== '') {
    $baseSql .= " AND pp.industry_type = ?";
    $params[] = $industryFilter;
}

$baseSql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($baseSql);
$stmt->execute($params);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($completionFilter !== '') {
    $partners = array_values(array_filter($partners, function ($row) use ($completionFilter) {
        $completion = partnerProfileCompletion($row);
        return match ($completionFilter) {
            'complete' => $completion === 100,
            'good' => $completion >= 70 && $completion < 100,
            'incomplete' => $completion < 70,
            default => true
        };
    }));
}

$allStmt = $pdo->prepare("SELECT u.status, pp.company_name, pp.industry_type, pp.contact_person, pp.phone, pp.address, pp.website, pp.company_description FROM users u LEFT JOIN partner_profiles pp ON u.id = pp.user_id WHERE u.role = 'partner'");
$allStmt->execute();
$allPartners = $allStmt->fetchAll(PDO::FETCH_ASSOC);

$industryStmt = $pdo->query("SELECT DISTINCT industry_type FROM partner_profiles WHERE industry_type IS NOT NULL AND industry_type <> '' ORDER BY industry_type ASC");
$industries = $industryStmt->fetchAll(PDO::FETCH_COLUMN);

$totalPartners = count($allPartners);
$activePartners = count(array_filter($allPartners, fn($row) => strtolower((string) ($row['status'] ?? '')) === 'active'));
$inactivePartners = count(array_filter($allPartners, fn($row) => strtolower((string) ($row['status'] ?? '')) === 'inactive'));
$pendingPartners = count(array_filter($allPartners, fn($row) => strtolower((string) ($row['status'] ?? '')) === 'pending'));
$completeProfiles = count(array_filter($allPartners, fn($row) => partnerProfileCompletion($row) === 100));
$filteredCount = count($partners);

$pageTitle = 'Industry Partner Accounts';
$pageSubtitle = 'Manage employer accounts, company profiles, and access status.';
$activePage = 'partners';

require_once '../includes/dashboard_header.php';
?>

<style>
    .partner-command-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 30px;
        color: #fff;
        background:
            radial-gradient(circle at top right, rgba(255,255,255,.18), transparent 34%),
            linear-gradient(135deg, #4b0000 0%, #7a1111 48%, #2a0000 100%);
        box-shadow: 0 24px 50px rgba(79, 0, 0, .18);
        margin-bottom: 22px;
    }

    .partner-command-hero:before {
        content: '';
        position: absolute;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .09);
        right: -72px;
        bottom: -120px;
    }

    .partner-command-hero .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .14);
        border: 1px solid rgba(255, 255, 255, .18);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .partner-command-hero h1 {
        margin: 16px 0 8px;
        font-size: clamp(28px, 4vw, 44px);
        font-weight: 900;
        letter-spacing: -1.2px;
    }

    .partner-command-hero p {
        max-width: 760px;
        margin: 0;
        color: rgba(255,255,255,.82);
        line-height: 1.7;
    }

    .partner-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 22px;
    }

    .partner-btn-white,
    .partner-btn-ghost,
    .partner-btn-maroon,
    .partner-btn-soft,
    .partner-btn-danger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 14px;
        padding: 10px 15px;
        font-weight: 800;
        text-decoration: none;
        border: 0;
        transition: .2s ease;
        white-space: nowrap;
    }

    .partner-btn-white {
        background: #fff;
        color: #620909;
        box-shadow: 0 12px 24px rgba(0,0,0,.12);
    }

    .partner-btn-ghost {
        background: rgba(255,255,255,.12);
        color: #fff;
        border: 1px solid rgba(255,255,255,.2);
    }

    .partner-btn-maroon {
        background: #7a1111;
        color: #fff;
        box-shadow: 0 10px 20px rgba(122, 17, 17, .18);
    }

    .partner-btn-soft {
        background: #fff7f7;
        color: #7a1111;
        border: 1px solid #efdada;
    }

    .partner-btn-danger {
        background: #fff3f3;
        color: #9f1d1d;
        border: 1px solid #f3d1d1;
    }

    .partner-btn-white:hover,
    .partner-btn-ghost:hover,
    .partner-btn-maroon:hover,
    .partner-btn-soft:hover,
    .partner-btn-danger:hover {
        transform: translateY(-1px);
        filter: brightness(.98);
    }

    .partner-stats-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .partner-metric-card {
        background: #fff;
        border: 1px solid #f0dfdf;
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 14px 35px rgba(83, 0, 0, .06);
    }

    .partner-metric-card .label {
        color: #8c7575;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .partner-metric-card .value {
        margin-top: 8px;
        font-size: 30px;
        font-weight: 900;
        color: #2f2222;
        letter-spacing: -.8px;
    }

    .partner-metric-card .note {
        margin-top: 4px;
        color: #8a7676;
        font-size: 13px;
    }

    .partner-filter-panel,
    .partner-list-panel {
        background: #fff;
        border: 1px solid #f0dfdf;
        border-radius: 26px;
        box-shadow: 0 18px 45px rgba(83, 0, 0, .06);
        padding: 22px;
        margin-bottom: 22px;
    }

    .partner-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .partner-panel-title {
        font-size: 18px;
        font-weight: 900;
        color: #2c2020;
    }

    .partner-panel-subtitle {
        color: #806f6f;
        margin-top: 4px;
        font-size: 14px;
    }

    .partner-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 11px;
        border-radius: 999px;
        background: #fff5f5;
        color: #7a1111;
        border: 1px solid #efdada;
        font-size: 12px;
        font-weight: 800;
    }

    .partner-table-wrap {
        overflow-x: auto;
    }

    .partner-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 12px;
    }

    .partner-table thead th {
        color: #8a7676;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .06em;
        padding: 0 14px 4px;
        white-space: nowrap;
    }

    .partner-table tbody tr {
        background: #fff;
        box-shadow: 0 10px 26px rgba(64, 0, 0, .05);
    }

    .partner-table tbody td {
        padding: 16px 14px;
        vertical-align: middle;
        border-top: 1px solid #f2e5e5;
        border-bottom: 1px solid #f2e5e5;
    }

    .partner-table tbody td:first-child {
        border-left: 1px solid #f2e5e5;
        border-top-left-radius: 18px;
        border-bottom-left-radius: 18px;
    }

    .partner-table tbody td:last-child {
        border-right: 1px solid #f2e5e5;
        border-top-right-radius: 18px;
        border-bottom-right-radius: 18px;
    }

    .company-block {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 280px;
    }

    .company-avatar {
        width: 46px;
        height: 46px;
        border-radius: 16px;
        background: linear-gradient(135deg, #7a1111, #3b0202);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        flex: 0 0 46px;
    }

    .company-name {
        color: #2d2020;
        font-weight: 900;
        line-height: 1.25;
    }

    .company-email,
    .partner-muted {
        color: #806f6f;
        font-size: 13px;
    }

    .partner-status {
        display: inline-flex;
        align-items: center;
        padding: 7px 11px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        text-transform: capitalize;
        white-space: nowrap;
    }

    .partner-status-active { background: #ecfdf3; color: #087a37; border: 1px solid #c8f1d8; }
    .partner-status-inactive { background: #fff1f1; color: #b42318; border: 1px solid #f7d2d2; }
    .partner-status-pending { background: #fff8e6; color: #9b6200; border: 1px solid #f7e0aa; }
    .partner-status-neutral { background: #f4f4f5; color: #52525b; border: 1px solid #e4e4e7; }

    .completion-wrap {
        min-width: 150px;
    }

    .completion-top {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        font-size: 12px;
        color: #806f6f;
        font-weight: 800;
        margin-bottom: 7px;
    }

    .completion-bar {
        height: 9px;
        border-radius: 999px;
        background: #f4e8e8;
        overflow: hidden;
    }

    .completion-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #7a1111, #c45050);
    }

    .partner-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .partner-action-mini {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 11px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
        white-space: nowrap;
    }

    .partner-action-view { color: #7a1111; background: #fff7f7; border: 1px solid #efdada; }
    .partner-action-toggle { color: #fff; background: #7a1111; border: 1px solid #7a1111; }
    .partner-action-toggle.inactive { color: #9f1d1d; background: #fff3f3; border-color: #f3d1d1; }

    .partner-detail-card {
        background: #fffafa;
        border: 1px solid #f2e1e1;
        border-radius: 18px;
        padding: 14px;
        margin-top: 12px;
        display: none;
    }

    .partner-detail-card.show {
        display: block;
    }

    .partner-detail-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .partner-detail-item strong {
        display: block;
        color: #4b3737;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 4px;
    }

    .partner-detail-item span {
        color: #7f6969;
        font-size: 13px;
        line-height: 1.5;
    }

    .partner-empty-state {
        text-align: center;
        padding: 44px 18px;
        border-radius: 22px;
        background: #fffafa;
        border: 1px dashed #e8cccc;
    }

    .partner-empty-state h3 {
        font-weight: 900;
        color: #2f2222;
        margin-bottom: 8px;
    }

    .partner-empty-state p {
        color: #806f6f;
        margin: 0;
    }

    @media (max-width: 1200px) {
        .partner-stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .partner-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 768px) {
        .partner-command-hero { padding: 24px; border-radius: 22px; }
        .partner-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .partner-detail-grid { grid-template-columns: 1fr; }
        .partner-table thead { display: none; }
        .partner-table, .partner-table tbody, .partner-table tr, .partner-table td { display: block; width: 100%; }
        .partner-table tbody tr { border: 1px solid #f2e5e5; border-radius: 18px; padding: 14px; margin-bottom: 14px; }
        .partner-table tbody td { border: 0 !important; padding: 8px 0; }
        .partner-table tbody td:first-child, .partner-table tbody td:last-child { border-radius: 0; }
        .partner-actions { justify-content: flex-start; }
    }

    @media (max-width: 520px) {
        .partner-stats-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="partner-command-hero fade-in">
    <div class="eyebrow">Partner Operations</div>
    <h1>Industry Partner Command Center</h1>
    <p>
        Review employer accounts, monitor profile readiness, and control partner access for job posting and recruitment activities.
    </p>
    <div class="partner-hero-actions">
        <a href="partner_accounts.php" class="partner-btn-white">Refresh Directory</a>
        <a href="job_posts.php" class="partner-btn-ghost">Review Job Posts</a>
    </div>
</div>

<div class="partner-stats-grid fade-in">
    <div class="partner-metric-card">
        <div class="label">Total Partners</div>
        <div class="value"><?= $totalPartners ?></div>
        <div class="note">Registered employer accounts</div>
    </div>

    <div class="partner-metric-card">
        <div class="label">Active</div>
        <div class="value"><?= $activePartners ?></div>
        <div class="note">Can access partner portal</div>
    </div>

    <div class="partner-metric-card">
        <div class="label">Inactive</div>
        <div class="value"><?= $inactivePartners ?></div>
        <div class="note">Access currently disabled</div>
    </div>

    <div class="partner-metric-card">
        <div class="label">Pending</div>
        <div class="value"><?= $pendingPartners ?></div>
        <div class="note">Needs admin review</div>
    </div>

    <div class="partner-metric-card">
        <div class="label">Complete Profiles</div>
        <div class="value"><?= $completeProfiles ?></div>
        <div class="note">100% company profile</div>
    </div>
</div>

<div class="partner-filter-panel fade-in">
    <div class="partner-panel-head">
        <div>
            <div class="partner-panel-title">Search and Filter Partners</div>
            <div class="partner-panel-subtitle">Find companies by name, contact person, email, industry, status, or profile readiness.</div>
        </div>
        <span class="partner-chip"><?= $filteredCount ?> result<?= $filteredCount === 1 ? '' : 's' ?></span>
    </div>

    <form method="GET" action="">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4 col-md-6">
                <label class="form-label">Search</label>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Company, owner, email, phone, website..."
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label">Industry</label>
                <select name="industry" class="form-control">
                    <option value="">All Industries</option>
                    <?php foreach ($industries as $industry): ?>
                        <option value="<?= htmlspecialchars($industry) ?>" <?= $industryFilter === $industry ? 'selected' : '' ?>>
                            <?= htmlspecialchars($industry) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Profile</label>
                <select name="completion" class="form-control">
                    <option value="">All Profiles</option>
                    <option value="complete" <?= $completionFilter === 'complete' ? 'selected' : '' ?>>Complete</option>
                    <option value="good" <?= $completionFilter === 'good' ? 'selected' : '' ?>>Good Progress</option>
                    <option value="incomplete" <?= $completionFilter === 'incomplete' ? 'selected' : '' ?>>Needs Update</option>
                </select>
            </div>

            <div class="col-lg-1 col-md-12 d-grid">
                <button type="submit" class="partner-btn-maroon">Filter</button>
            </div>
        </div>

        <?php if ($search !== '' || $statusFilter !== '' || $industryFilter !== '' || $completionFilter !== ''): ?>
            <div class="mt-3">
                <a href="partner_accounts.php" class="partner-btn-soft">Clear Filters</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="partner-list-panel fade-in">
    <div class="partner-panel-head">
        <div>
            <div class="partner-panel-title">Partner Account Directory</div>
            <div class="partner-panel-subtitle">Manage company identity, recruiter information, profile completion, and account access.</div>
        </div>
        <span class="partner-chip">Directory View</span>
    </div>

    <?php if (empty($partners)): ?>
        <div class="partner-empty-state">
            <h3>No partner accounts found</h3>
            <p>Try adjusting the search terms or filters to see more partner records.</p>
        </div>
    <?php else: ?>
        <div class="partner-table-wrap">
            <table class="partner-table">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Industry</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Profile</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($partners as $row): ?>
                        <?php
                            $completion = partnerProfileCompletion($row);
                            $currentStatus = strtolower((string) ($row['status'] ?? 'inactive'));
                            $toggleTo = $currentStatus === 'active' ? 'inactive' : 'active';
                            $toggleLabel = $currentStatus === 'active' ? 'Deactivate' : 'Activate';
                            $companyName = trim((string) ($row['company_name'] ?? ''));
                            if ($companyName === '') {
                                $companyName = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                            }
                            if ($companyName === '') {
                                $companyName = 'Unnamed Partner';
                            }
                            $initials = strtoupper(substr($companyName, 0, 1));
                            $detailId = 'partner-detail-' . (int) $row['id'];
                        ?>
                        <tr>
                            <td>
                                <div class="company-block">
                                    <div>
                                        <div class="company-name"><?= htmlspecialchars($companyName) ?></div>
                                        <div class="company-email"><?= htmlspecialchars($row['email'] ?? '—') ?></div>
                                        <div class="partner-muted">Registered <?= !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : '—' ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="company-name" style="font-size:14px;"><?= htmlspecialchars($row['industry_type'] ?? '—') ?></div>
                                <div class="partner-muted"><?= !empty($row['website']) ? htmlspecialchars($row['website']) : 'No website listed' ?></div>
                            </td>
                            <td>
                                <div class="company-name" style="font-size:14px;"><?= htmlspecialchars($row['contact_person'] ?? '—') ?></div>
                                <div class="partner-muted"><?= htmlspecialchars($row['phone'] ?? 'No phone listed') ?></div>
                            </td>
                            <td>
                                <span class="<?= statusBadgeClass($currentStatus) ?>"><?= htmlspecialchars($currentStatus ?: 'unknown') ?></span>
                            </td>
                            <td>
                                <div class="completion-wrap">
                                    <div class="completion-top">
                                        <span><?= completionTone($completion) ?></span>
                                        <span><?= $completion ?>%</span>
                                    </div>
                                    <div class="completion-bar">
                                        <div class="completion-fill" style="width: <?= $completion ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="partner-actions">
                                    <button type="button" class="partner-action-mini partner-action-view" data-detail-target="<?= htmlspecialchars($detailId) ?>">
                                        View Details
                                    </button>
                                    <a href="update_user_status.php?id=<?= (int) $row['id'] ?>&status=<?= urlencode($toggleTo) ?>&role=partner"
                                       class="partner-action-mini partner-action-toggle <?= $currentStatus === 'active' ? 'inactive' : '' ?>"
                                       onclick="return confirm('Are you sure you want to <?= strtolower($toggleLabel) ?> this partner account?');">
                                        <?= htmlspecialchars($toggleLabel) ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="6" style="padding-top:0; border:0; box-shadow:none; background:transparent;">
                                <div id="<?= htmlspecialchars($detailId) ?>" class="partner-detail-card">
                                    <div class="partner-detail-grid">
                                        <div class="partner-detail-item">
                                            <strong>Account Owner</strong>
                                            <span><?= htmlspecialchars(trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?: '—') ?></span>
                                        </div>
                                        <div class="partner-detail-item">
                                            <strong>Company Name</strong>
                                            <span><?= htmlspecialchars($row['company_name'] ?? '—') ?></span>
                                        </div>
                                        <div class="partner-detail-item">
                                            <strong>Contact Person</strong>
                                            <span><?= htmlspecialchars($row['contact_person'] ?? '—') ?></span>
                                        </div>
                                        <div class="partner-detail-item">
                                            <strong>Industry</strong>
                                            <span><?= htmlspecialchars($row['industry_type'] ?? '—') ?></span>
                                        </div>
                                        <div class="partner-detail-item">
                                            <strong>Phone</strong>
                                            <span><?= htmlspecialchars($row['phone'] ?? '—') ?></span>
                                        </div>
                                        <div class="partner-detail-item">
                                            <strong>Website</strong>
                                            <span><?= htmlspecialchars($row['website'] ?? '—') ?></span>
                                        </div>
                                        <div class="partner-detail-item" style="grid-column: 1 / -1;">
                                            <strong>Address</strong>
                                            <span><?= htmlspecialchars($row['address'] ?? '—') ?></span>
                                        </div>
                                        <div class="partner-detail-item" style="grid-column: 1 / -1;">
                                            <strong>Company Description</strong>
                                            <span><?= !empty($row['company_description']) ? nl2br(htmlspecialchars($row['company_description'])) : '—' ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('[data-detail-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(this.getAttribute('data-detail-target'));
            if (!target) return;
            target.classList.toggle('show');
            this.textContent = target.classList.contains('show') ? 'Hide Details' : 'View Details';
        });
    });
</script>

<?php require_once '../includes/dashboard_footer.php'; ?>
