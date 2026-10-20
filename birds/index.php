<?php
declare(strict_types=1);

$pageTitle = 'Birds Empowerment Project';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/* ── schema helpers ─────────────────────────────────────── */
function bidx_table(mysqli $conn, string $table): bool
{
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $res && $res->num_rows > 0;
}

function bidx_col(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $res = $conn->query(
        "SHOW COLUMNS FROM `" . $conn->real_escape_string($table) .
        "` LIKE '"            . $conn->real_escape_string($column) . "'"
    );
    return $cache[$key] = ($res && $res->num_rows > 0);
}

function bidx_count(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function bidx_rows(mysqli $conn, string $sql): array
{
    $rows = [];
    $res  = $conn->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

function bidx_due_col(mysqli $conn): string
{
    if (bidx_col($conn, 'bird_allocations', 'return_due_date'))      return 'return_due_date';
    if (bidx_col($conn, 'bird_allocations', 'expected_return_date')) return 'expected_return_date';
    return 'return_due_date';
}

function bidx_allocation_no(array $row): string
{
    if (!empty($row['allocation_no'])) return (string)$row['allocation_no'];
    return 'ALLOC-' . date('Y') . '-' . str_pad((string)($row['id'] ?? 0), 5, '0', STR_PAD_LEFT);
}

function bidx_date_display(?string $date): string
{
    if (empty($date) || $date === '0000-00-00') return '-';
    return date('d M Y', strtotime($date));
}

/* ── bootstrap ──────────────────────────────────────────── */
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

$requiredTables = ['birds', 'bird_allocations', 'members', 'youth'];
$missingTables  = array_filter($requiredTables, fn($t) => !bidx_table($conn, $t));

$tab         = $_GET['tab'] ?? 'active';
$allowedTabs = ['active', 'overdue', 'completed', 'birds', 'rotation'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'active';

/* ── dynamic column names ───────────────────────────────── */
$dueCol     = empty($missingTables) ? bidx_due_col($conn) : 'return_due_date';
$actualCol  = empty($missingTables) && bidx_col($conn, 'bird_allocations', 'actual_return_date') ? 'actual_return_date' : '';
$chickCol   = empty($missingTables) && bidx_col($conn, 'bird_allocations', 'chick_count')        ? 'chick_count'        : '';

$allocationNoSelect = (empty($missingTables) && bidx_col($conn, 'bird_allocations', 'allocation_no'))
    ? 'ba.allocation_no'
    : "CONCAT('ALLOC-', YEAR(COALESCE(ba.created_at, CURDATE())), '-', LPAD(ba.id, 5, '0')) AS allocation_no";

/* ── data ───────────────────────────────────────────────── */
if (empty($missingTables)) {
    /* auto-mark overdue */
    $conn->query("
        UPDATE bird_allocations SET status = 'Overdue'
        WHERE status = 'Active'
          AND `$dueCol` IS NOT NULL AND `$dueCol` <> '0000-00-00'
          AND `$dueCol` < CURDATE()
    ");

    $totalBirds      = bidx_count($conn, "SELECT COUNT(*)                          total FROM birds");
    $availableBirds  = bidx_count($conn, "SELECT COUNT(*)                          total FROM birds WHERE status='Available'");
    $allocatedBirds  = bidx_count($conn, "SELECT COUNT(*)                          total FROM bird_allocations WHERE status IN('Active','Overdue')");
    $overdueBirds    = bidx_count($conn, "SELECT COUNT(*)                          total FROM bird_allocations WHERE status='Overdue'");
    $completedCycles = bidx_count($conn, "SELECT COUNT(*)                          total FROM bird_allocations WHERE status='Completed'");
    $returnedChicks  = $chickCol
        ? bidx_count($conn, "SELECT COALESCE(SUM(`$chickCol`),0) total FROM bird_allocations WHERE status='Completed'")
        : 0;

    $projects = bidx_table($conn, 'projects')
        ? bidx_rows($conn, "SELECT id, name FROM projects WHERE type='Birds' AND status='Active' ORDER BY name")
        : [];

    $availableBirdRows = bidx_rows($conn, "
        SELECT id, bird_no, breed, gender FROM birds WHERE status='Available' ORDER BY bird_no
    ");

    $activeAllocationRows = bidx_rows($conn, "
        SELECT ba.*, $allocationNoSelect,
               ba.`allocated_on`   AS allocated_date,
               ba.`$dueCol`        AS due_date,
               b.bird_no, b.breed,
               y.youth_no, y.id AS yid,
               m.first_name, m.last_name, m.phone
        FROM bird_allocations ba
        JOIN birds   b ON b.id = ba.bird_id
        JOIN youth   y ON y.id = ba.youth_id
        JOIN members m ON m.id = y.member_id
        WHERE ba.status IN('Active','Overdue')
        ORDER BY ba.`$dueCol` ASC
    ");

    $eligibleYouth = bidx_rows($conn, "
        SELECT y.id, y.youth_no, m.first_name, m.last_name, m.phone
        FROM youth   y
        JOIN members m ON m.id = y.member_id
        WHERE y.status = 'Active'
          AND y.id NOT IN (SELECT youth_id FROM bird_allocations WHERE status IN('Active','Overdue'))
        ORDER BY m.first_name, m.last_name
    ");
} else {
    $totalBirds = $availableBirds = $allocatedBirds = $overdueBirds = $completedCycles = $returnedChicks = 0;
    $projects = $availableBirdRows = $activeAllocationRows = $eligibleYouth = [];
}

$breeds   = ['Local Hen','Improved Kienyeji','Kuroiler','KARI Improved Kienyeji','Broiler','Layer','Turkey','Duck'];
$statuses = ['Available','Allocated','Returned','Deceased'];
?>

<div class="main-content">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area">

        <!-- ── Page header ──────────────────────────────────── -->
        <div class="module-page-header">
            <div class="module-title">
                <h2><i class="fas fa-feather-alt" style="color:var(--accent)"></i> Birds Empowerment Project</h2>
                <p>Annual rotational bird-rearing project for youth empowerment</p>
            </div>
            <div class="module-actions">
                <button type="button" class="btn btn-outline"  data-open-modal="addBirdModal">
                    <i class="fas fa-plus"></i> Add Bird
                </button>
                <button type="button" class="btn btn-accent"   data-open-modal="allocateBirdModal">
                    <i class="fas fa-user-plus"></i> New Allocation
                </button>
                <button type="button" class="btn btn-success"  data-open-modal="returnBirdModal">
                    <i class="fas fa-undo"></i> Record Return
                </button>
            </div>
        </div>

        <?php if (function_exists('showFlash')) showFlash(); ?>

        <!-- Global AJAX alert -->
        <div id="birdsAjaxAlert" class="birds-alert"></div>

        <!-- ── Missing tables notice ────────────────────────── -->
        <?php if (!empty($missingTables)): ?>
            <div class="info-box danger">
                <i class="fa fa-exclamation-triangle"></i>
                <div>
                    <h4>Missing Database Tables</h4>
                    <p>The following tables must exist before using this module:
                       <strong><?= h(implode(', ', $missingTables)) ?></strong>
                    </p>
                </div>
            </div>

        <?php else: ?>

        <!-- ── How it works ─────────────────────────────────── -->
        <div class="info-box gold">
            <i class="fas fa-info-circle"></i>
            <div>
                <h4>How the Rotation Works</h4>
                <p>A hen is issued to a youth member for one year. After one year the youth returns one chick to
                   the church. The returned chick grows and is allocated to the next youth member.</p>
                <p><strong>Notifications:</strong> Youth receive email / SMS confirmation after allocation and
                   return. Weekly reminders start two months before the expected return date.</p>
            </div>
        </div>

        <!-- ── Stat cards (3 × 2) ───────────────────────────── -->
        <div class="birds-stat-grid">

            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(74,30,143,.12);color:var(--primary)">
                    <i class="fas fa-feather-alt"></i>
                </div>
                <div class="stat-body">
                    <div class="stat-number"><?= $totalBirds ?></div>
                    <div class="stat-label">Total Birds</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(40,167,69,.12);color:var(--success)">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-body">
                    <div class="stat-number"><?= $availableBirds ?></div>
                    <div class="stat-label">Available Birds</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(212,160,23,.15);color:var(--accent)">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-body">
                    <div class="stat-number"><?= $allocatedBirds ?></div>
                    <div class="stat-label">Allocated</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(220,53,69,.12);color:var(--danger)">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="stat-body">
                    <div class="stat-number"><?= $overdueBirds ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(23,162,184,.12);color:var(--info)">
                    <i class="fas fa-sync"></i>
                </div>
                <div class="stat-body">
                    <div class="stat-number"><?= $completedCycles ?></div>
                    <div class="stat-label">Completed Cycles</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(74,30,143,.12);color:var(--primary)">
                    <i class="fas fa-egg"></i>
                </div>
                <div class="stat-body">
                    <div class="stat-number"><?= $returnedChicks ?></div>
                    <div class="stat-label">Returned Chicks</div>
                </div>
            </div>

        </div><!-- /.birds-stat-grid -->

        <!-- ── Tab navigation ───────────────────────────────── -->
        <?php
        $tabs = [
            'active'    => 'Active Allocations',
            'overdue'   => 'Overdue',
            'completed' => 'Completed',
            'birds'     => 'Bird Inventory',
            'rotation'  => 'Rotation History',
        ];
        ?>
        <div class="birds-tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="?tab=<?= h($key) ?>" class="<?= $tab === $key ? 'active' : '' ?>">
                    <?= h($label) ?>
                    <?php if ($key === 'overdue' && $overdueBirds > 0): ?>
                        <span class="badge badge-danger"><?= $overdueBirds ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>


        <!-- ════════════════════════════════════════════════════
             TAB: active | overdue
        ════════════════════════════════════════════════════ -->
        <?php if ($tab === 'active' || $tab === 'overdue'):
            $statusFilter = $tab === 'overdue' ? "ba.status='Overdue'" : "ba.status='Active'";
            $tabRows = bidx_rows($conn, "
                SELECT ba.*, $allocationNoSelect,
                       ba.`allocated_on` AS allocated_date,
                       ba.`$dueCol`      AS due_date,
                       b.bird_no, b.breed, b.gender AS bird_gender,
                       y.youth_no,
                       m.first_name, m.last_name, m.phone
                FROM bird_allocations ba
                JOIN birds   b ON b.id = ba.bird_id
                JOIN youth   y ON y.id = ba.youth_id
                JOIN members m ON m.id = y.member_id
                WHERE $statusFilter
                ORDER BY ba.`$dueCol` ASC
            ");
        ?>

        <div class="card module-card">
            <div class="card-header">
                <span><i class="fas fa-users"></i>
                    <?= $tab === 'overdue' ? 'Overdue Allocations' : 'Active Allocations' ?>
                </span>
                <button type="button" class="btn btn-sm btn-accent" data-open-modal="allocateBirdModal">
                    <i class="fas fa-plus"></i> New Allocation
                </button>
            </div>

            <div class="module-table-wrap">
                <table class="table module-table">
                    <thead>
                        <tr>
                            <th>Allocation No</th>
                            <th>Bird</th>
                            <th>Youth</th>
                            <th>Phone</th>
                            <th>Allocated</th>
                            <th>Return Due</th>
                            <th>Days</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($tabRows): foreach ($tabRows as $r):
                        $days = 0;
                        if (!empty($r['due_date']) && $r['due_date'] !== '0000-00-00') {
                            $diff = (new DateTime())->diff(new DateTime($r['due_date']));
                            $days = $diff->invert ? -$diff->days : $diff->days;
                        }
                    ?>
                        <tr>
                            <td><span class="badge badge-primary"><?= h(bidx_allocation_no($r)) ?></span></td>
                            <td>
                                <strong><i class="fas fa-feather-alt" style="color:var(--accent)"></i>
                                    <?= h($r['bird_no']) ?></strong><br>
                                <small class="text-muted"><?= h($r['breed']) ?> · <?= h($r['bird_gender']) ?></small>
                            </td>
                            <td>
                                <strong><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong><br>
                                <small class="badge badge-gold"><?= h($r['youth_no']) ?></small>
                            </td>
                            <td><?= h($r['phone'] ?: '-') ?></td>
                            <td><?= h(bidx_date_display($r['allocated_date'] ?? null)) ?></td>
                            <td><?= h(bidx_date_display($r['due_date']       ?? null)) ?></td>
                            <td>
                                <?php if ($days >= 0): ?>
                                    <span class="badge <?= $days <= 30 ? 'badge-warning' : 'badge-success' ?>">
                                        <?= $days ?> days left
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?= abs($days) ?> overdue</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-success btn-icon open-return"
                                        data-alloc-id="<?= (int)$r['id'] ?>"
                                        title="Record Return">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <div class="icon"></div>
                                    <p>No <?= $tab === 'overdue' ? 'overdue' : 'active' ?> allocations found.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>


        <!-- ════════════════════════════════════════════════════
             TAB: completed | rotation
        ════════════════════════════════════════════════════ -->
        <?php elseif ($tab === 'completed' || $tab === 'rotation'):
            $actualSelect = $actualCol ? "ba.`$actualCol` AS actual_return_date," : "NULL AS actual_return_date,";
            $chickSelect  = $chickCol  ? "ba.`$chickCol`  AS chicks,"             : "0    AS chicks,";
            $whereClause  = $tab === 'completed' ? "WHERE ba.status='Completed'" : '';
            $tabRows = bidx_rows($conn, "
                SELECT ba.*, $allocationNoSelect,
                       ba.`allocated_on` AS allocated_date,
                       ba.`$dueCol`      AS due_date,
                       $actualSelect
                       $chickSelect
                       b.bird_no, b.breed,
                       y.youth_no,
                       m.first_name, m.last_name
                FROM bird_allocations ba
                JOIN birds   b ON b.id = ba.bird_id
                JOIN youth   y ON y.id = ba.youth_id
                JOIN members m ON m.id = y.member_id
                $whereClause
                ORDER BY ba.id DESC
            ");
        ?>

        <div class="card module-card">
            <div class="card-header">
                <span><i class="fas fa-history"></i>
                    <?= $tab === 'completed' ? 'Completed Returns' : 'Full Rotation History' ?>
                </span>
            </div>

            <div class="module-table-wrap">
                <table class="table module-table">
                    <thead>
                        <tr>
                            <th>Allocation No</th>
                            <th>Bird</th>
                            <th>Youth</th>
                            <th>Allocated</th>
                            <th>Due</th>
                            <th>Returned</th>
                            <th>Chicks</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($tabRows): foreach ($tabRows as $r): ?>
                        <tr>
                            <td><span class="badge badge-primary"><?= h(bidx_allocation_no($r)) ?></span></td>
                            <td><?= h($r['bird_no'] . ' - ' . $r['breed']) ?></td>
                            <td>
                                <strong><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong><br>
                                <small class="text-muted"><?= h($r['youth_no']) ?></small>
                            </td>
                            <td><?= h(bidx_date_display($r['allocated_date']    ?? null)) ?></td>
                            <td><?= h(bidx_date_display($r['due_date']           ?? null)) ?></td>
                            <td><?= h(bidx_date_display($r['actual_return_date'] ?? null)) ?></td>
                            <td><?= (int)($r['chicks'] ?? 0) ?></td>
                            <td>
                                <?php
                                $sc = match($r['status'] ?? '') {
                                    'Completed' => 'badge-success',
                                    'Overdue'   => 'badge-danger',
                                    default     => 'badge-primary',
                                };
                                ?>
                                <span class="badge <?= $sc ?>"><?= h($r['status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state"><p>No records found.</p></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>


        <!-- ════════════════════════════════════════════════════
             TAB: birds (inventory)
        ════════════════════════════════════════════════════ -->
        <?php elseif ($tab === 'birds'):
            $projectJoin   = bidx_table($conn, 'projects') ? "LEFT JOIN projects p ON p.id = b.project_id"  : '';
            $projectSelect = bidx_table($conn, 'projects') ? "p.name AS project_name"                       : "NULL AS project_name";
            $tabRows = bidx_rows($conn, "
                SELECT b.*, $projectSelect FROM birds b $projectJoin ORDER BY b.id DESC
            ");
        ?>

        <div class="card module-card">
            <div class="card-header">
                <span><i class="fas fa-feather-alt"></i> Bird Inventory</span>
                <button type="button" class="btn btn-sm btn-primary" data-open-modal="addBirdModal">
                    <i class="fas fa-plus"></i> Add Bird
                </button>
            </div>

            <div class="module-table-wrap">
                <table class="table module-table">
                    <thead>
                        <tr>
                            <th>Bird No</th>
                            <th>Breed</th>
                            <th>Gender</th>
                            <th>Color</th>
                            <th>Weight</th>
                            <th>Project</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($tabRows): foreach ($tabRows as $r):
                        $sc = match($r['status'] ?? '') {
                            'Available' => 'badge-success',
                            'Allocated' => 'badge-primary',
                            default     => 'badge-secondary',
                        };
                    ?>
                        <tr>
                            <td><strong><?= h($r['bird_no']) ?></strong></td>
                            <td><?= h($r['breed'])  ?></td>
                            <td><?= h($r['gender']) ?></td>
                            <td><?= h($r['color'])  ?></td>
                            <td><?= h($r['weight_kg']) ?> kg</td>
                            <td><?= h($r['project_name'] ?: '-') ?></td>
                            <td><span class="badge <?= $sc ?>"><?= h($r['status']) ?></span></td>
                            <td>
                                <div class="birds-row-actions">
                                    <button type="button" class="btn btn-sm btn-info btn-icon edit-bird"
                                            data-id="<?= (int)$r['id'] ?>" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger btn-icon delete-bird"
                                            data-id="<?= (int)$r['id'] ?>"
                                            data-no="<?= h($r['bird_no']) ?>" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state"><p>No birds in inventory.</p></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; /* tabs */ ?>
        <?php endif; /* missing tables */ ?>


        <!-- ════════════════════════════════════════════════════
             MODALS  (inside .content-area so stacking context
             stays correct; backdrop is position:fixed)
        ════════════════════════════════════════════════════ -->

        <!-- ── Add Bird ──────────────────────────────────────── -->
        <div class="birds-modal" id="addBirdModal" aria-hidden="true">
            <div class="birds-modal-dialog">
                <div class="birds-modal-header">
                    <h3><i class="fas fa-plus"></i> Add Bird(s)</h3>
                    <button type="button" class="birds-close" data-close-modal>&times;</button>
                </div>
                <div class="birds-modal-body">
                    <form class="birds-ajax-form">
                        <input type="hidden" name="action" value="add_bird">
                        <div class="birds-form-grid">
                            <div class="form-group">
                                <label>Number of Birds</label>
                                <input type="number" name="count" class="form-control" min="1" max="50" value="1">
                            </div>
                            <div class="form-group">
                                <label>Breed / Type <span class="req">*</span></label>
                                <select name="breed" class="form-control" required>
                                    <?php foreach ($breeds as $br): ?>
                                        <option value="<?= h($br) ?>"><?= h($br) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Gender</label>
                                <select name="gender" class="form-control">
                                    <option value="Hen">Hen</option>
                                    <option value="Cock">Cock</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Color / Markings</label>
                                <input type="text" name="color" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Weight (kg)</label>
                                <input type="number" name="weight_kg" class="form-control" step="0.1" value="0">
                            </div>
                            <div class="form-group">
                                <label>Acquired Date</label>
                                <input type="date" name="acquired_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label>Project</label>
                                <select name="project_id" class="form-control">
                                    <option value="">- None -</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" data-close-modal>Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Bird(s)
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div><!-- /#addBirdModal -->


        <!-- ── Edit Bird ─────────────────────────────────────── -->
        <div class="birds-modal" id="editBirdModal" aria-hidden="true">
            <div class="birds-modal-dialog">
                <div class="birds-modal-header">
                    <h3><i class="fas fa-edit"></i> Edit Bird <span id="editBirdNo"></span></h3>
                    <button type="button" class="birds-close" data-close-modal>&times;</button>
                </div>
                <div class="birds-modal-body">
                    <form class="birds-ajax-form">
                        <input type="hidden" name="action" value="edit_bird">
                        <input type="hidden" name="id"     id="editBirdId">
                        <div class="birds-form-grid">
                            <div class="form-group">
                                <label>Breed <span class="req">*</span></label>
                                <select name="breed" id="editBreed" class="form-control" required>
                                    <?php foreach ($breeds as $br): ?>
                                        <option value="<?= h($br) ?>"><?= h($br) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Gender</label>
                                <select name="gender" id="editGender" class="form-control">
                                    <option value="Hen">Hen</option>
                                    <option value="Cock">Cock</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Color</label>
                                <input type="text" name="color" id="editColor" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Weight (kg)</label>
                                <input type="number" name="weight_kg" id="editWeight" class="form-control" step="0.1">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="editStatus" class="form-control">
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?= h($s) ?>"><?= h($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" id="editNotes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" data-close-modal>Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Bird
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div><!-- /#editBirdModal -->


        <!-- ── Allocate Bird (multi-row) ────────────────────── -->
        <div class="birds-modal" id="allocateBirdModal" aria-hidden="true">
            <div class="birds-modal-dialog" style="max-width:min(820px,100%)">
                <div class="birds-modal-header">
                    <h3><i class="fas fa-user-plus"></i> Allocate Birds to Youth
                        <span class="badge badge-primary ml-2" id="allocRowCount" style="font-size:12px">1 pair</span>
                    </h3>
                    <button type="button" class="birds-close" data-close-modal>&times;</button>
                </div>
                <div class="birds-modal-body">
                    <form id="allocateBirdForm">
                        <input type="hidden" name="action" value="allocate_bird_bulk">

                        <!-- Shared date fields -->
                        <div class="birds-form-grid" style="margin-bottom:16px">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> Allocation Date</label>
                                <input type="date" name="allocation_date" id="allocDate"
                                       class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-check"></i> Return Due Date</label>
                                <input type="date" name="return_due_date" id="allocDue"
                                       class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                            </div>
                        </div>

                        <!-- Column headers -->
                        <div style="display:grid;grid-template-columns:1fr 1fr 34px;gap:8px;margin-bottom:4px;padding:0 2px">
                            <div style="font-size:11.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">
                                <i class="fas fa-feather-alt" style="color:var(--accent)"></i> Bird
                            </div>
                            <div style="font-size:11.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">
                                <i class="fas fa-user-graduate" style="color:var(--primary)"></i> Youth Member
                            </div>
                            <div></div>
                        </div>

                        <!-- Pairing rows (built from PHP data; JS can clone) -->
                        <div id="allocPairsContainer">
                            <!-- Row template - first row, not removable -->
                            <div class="alloc-pair-row" style="display:grid;grid-template-columns:1fr 1fr 34px;gap:8px;align-items:center;margin-bottom:8px">
                                <select name="bird_ids[]" class="form-control alloc-bird-sel" required>
                                    <option value="">- Select Bird -</option>
                                    <?php foreach ($availableBirdRows as $b): ?>
                                        <option value="<?= (int)$b['id'] ?>"
                                                data-no="<?= h($b['bird_no']) ?>">
                                            <?= h($b['bird_no'] . ' | ' . $b['breed'] . ' | ' . $b['gender']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="youth_ids[]" class="form-control alloc-youth-sel" required>
                                    <option value="">- Select Youth -</option>
                                    <?php foreach ($eligibleYouth as $y): ?>
                                        <option value="<?= (int)$y['id'] ?>"
                                                data-name="<?= h(trim($y['first_name'].' '.$y['last_name'])) ?>">
                                            <?= h(trim($y['first_name'].' '.$y['last_name']).' ('.$y['youth_no'].')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-danger btn-icon remove-alloc-row"
                                        style="display:none" title="Remove row">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Add row button -->
                        <button type="button" id="btnAddAllocRow" class="btn btn-outline btn-sm"
                                style="margin-bottom:16px"
                                <?= empty($availableBirdRows) || empty($eligibleYouth) ? 'disabled' : '' ?>>
                            <i class="fas fa-plus"></i> Add Another Pair
                        </button>

                        <?php if (empty($availableBirdRows)): ?>
                        <div class="info-box warning" style="margin-bottom:14px">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div><p>No available birds. Add birds to inventory first.</p></div>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($eligibleYouth)): ?>
                        <div class="info-box warning" style="margin-bottom:14px">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div><p>No eligible youth. All active youth members already have allocations.</p></div>
                        </div>
                        <?php endif; ?>

                        <!-- Summary pill -->
                        <div id="allocSummary" style="display:none;margin-bottom:14px">
                            <div class="info-box" style="padding:10px 14px">
                                <i class="fas fa-info-circle"></i>
                                <div id="allocSummaryText"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Remarks / Notes <small class="text-muted">(applied to all)</small></label>
                            <textarea name="remarks" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" data-close-modal>Cancel</button>
                            <button type="submit" class="btn btn-accent" id="allocSubmitBtn">
                                <i class="fas fa-check-circle"></i>
                                <span id="allocSubmitLabel">Allocate Bird</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div><!-- /#allocateBirdModal -->


        <!-- ── Record Return ─────────────────────────────────── -->
        <div class="birds-modal" id="returnBirdModal" aria-hidden="true">
            <div class="birds-modal-dialog">
                <div class="birds-modal-header">
                    <h3><i class="fas fa-undo"></i> Record Chick Return</h3>
                    <button type="button" class="birds-close" data-close-modal>&times;</button>
                </div>
                <div class="birds-modal-body">
                    <form class="birds-ajax-form">
                        <input type="hidden" name="action" value="return_bird">
                        <div class="form-group">
                            <label>Active Allocation <span class="req">*</span></label>
                            <select name="alloc_id" id="returnAllocId" class="form-control" required>
                                <option value="">- Select Allocation -</option>
                                <?php foreach ($activeAllocationRows as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>">
                                        <?= h(
                                            bidx_allocation_no($a)
                                            . ' | ' . $a['bird_no']
                                            . ' - ' . trim($a['first_name'] . ' ' . $a['last_name'])
                                            . ' | Due: ' . bidx_date_display($a['due_date'] ?? null)
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="birds-form-grid">
                            <div class="form-group">
                                <label>Return Date</label>
                                <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label>Chicks Returned</label>
                                <input type="number" name="chick_count" class="form-control" min="0" max="50" value="1">
                            </div>
                            <div class="form-group">
                                <label>Chick Condition</label>
                                <select name="chick_condition" class="form-control">
                                    <option>Excellent</option>
                                    <option selected>Good</option>
                                    <option>Fair</option>
                                    <option>Poor</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Next Youth <small class="text-muted">(optional)</small></label>
                                <select name="next_youth_id" class="form-control">
                                    <option value="">- Allocate Later -</option>
                                    <?php foreach ($eligibleYouth as $y): ?>
                                        <option value="<?= (int)$y['id'] ?>">
                                            <?= h(trim($y['first_name'] . ' ' . $y['last_name']) . ' (' . $y['youth_no'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" data-close-modal>Cancel</button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> Record Return
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div><!-- /#returnBirdModal -->

    </div><!-- /.content-area -->
</div><!-- /.main-content -->


<script>
(function () {
    'use strict';

    const ENDPOINT = 'process-birds.php';
    const alertBox = document.getElementById('birdsAjaxAlert');

    /* ── helpers ── */
    function showAlert(ok, msg) {
        if (!alertBox) return;
        alertBox.className   = 'birds-alert ' + (ok ? 'success' : 'error');
        alertBox.textContent = msg || (ok ? 'Action completed.' : 'Action failed.');
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('show');
        el.setAttribute('aria-hidden', 'false');
        document.body.classList.add('cms-modal-open');
    }

    function closeModal(target) {
        const modal = target.closest
            ? target.closest('.birds-modal')
            : target;                          
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        /* only remove body lock when no other modal is open */
        if (!document.querySelector('.birds-modal.show')) {
            document.body.classList.remove('cms-modal-open');
        }
    }

    /* ── delegated click handler ── */
    document.addEventListener('click', async function (e) {

        /* open modal */
        const opener = e.target.closest('[data-open-modal]');
        if (opener) { e.preventDefault(); openModal(opener.getAttribute('data-open-modal')); return; }

        /* close modal - button or backdrop click */
        if (e.target.matches('[data-close-modal]') || e.target.classList.contains('birds-modal')) {
            e.preventDefault(); closeModal(e.target); return;
        }

        /* "Record Return" quick-button in table rows */
        const returnBtn = e.target.closest('.open-return');
        if (returnBtn) {
            e.preventDefault();
            const sel = document.getElementById('returnAllocId');
            if (sel) sel.value = returnBtn.dataset.allocId || '';
            openModal('returnBirdModal');
            return;
        }

        /* edit bird */
        const editBtn = e.target.closest('.edit-bird');
        if (editBtn) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'get_bird');
            fd.append('id', editBtn.dataset.id || '0');

            try {
                const res  = await fetch(ENDPOINT, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();

                if (!data.success) { showAlert(false, data.message); return; }

                const b = data.bird || {};
                document.getElementById('editBirdId').value         = b.id         || '';
                document.getElementById('editBirdNo').textContent   = b.bird_no    ? '(' + b.bird_no + ')' : '';
                document.getElementById('editBreed').value          = b.breed      || '';
                document.getElementById('editGender').value         = b.gender     || 'Hen';
                document.getElementById('editColor').value          = b.color      || '';
                document.getElementById('editWeight').value         = b.weight_kg  || 0;
                document.getElementById('editStatus').value         = b.status     || 'Available';
                document.getElementById('editNotes').value          = b.notes      || '';
                openModal('editBirdModal');
            } catch { showAlert(false, 'Invalid server response.'); }
            return;
        }

        /* delete bird */
        const deleteBtn = e.target.closest('.delete-bird');
        if (deleteBtn) {
            e.preventDefault();
            if (!confirm('Delete bird ' + (deleteBtn.dataset.no || '') + '?')) return;

            const fd = new FormData();
            fd.append('action', 'delete_bird');
            fd.append('id', deleteBtn.dataset.id || '0');

            try {
                const res  = await fetch(ENDPOINT, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                showAlert(!!data.success, data.message);
                if (data.success) setTimeout(() => location.reload(), 800);
            } catch { showAlert(false, 'Invalid server response.'); }
        }
    });

    /* ── AJAX form submissions (add/edit/return - single forms) ── */
    document.querySelectorAll('.birds-ajax-form').forEach(form => {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const btn     = form.querySelector('button[type="submit"]');
            const oldHTML = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; }

            try {
                const res  = await fetch(ENDPOINT, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                showAlert(!!data.success, data.message);
                if (data.success) setTimeout(() => location.reload(), 900);
            } catch { showAlert(false, 'Invalid server response.'); }
            finally  { if (btn) { btn.disabled = false; btn.innerHTML = oldHTML; } }
        });
    });

    /* ══════════════════════════════════════════════════════════
       MULTI-ALLOCATION PAIRING UI
    ══════════════════════════════════════════════════════════ */
    (function () {

        const container    = document.getElementById('allocPairsContainer');
        const addBtn       = document.getElementById('btnAddAllocRow');
        const countBadge   = document.getElementById('allocRowCount');
        const summaryBox   = document.getElementById('allocSummary');
        const summaryText  = document.getElementById('allocSummaryText');
        const submitLabel  = document.getElementById('allocSubmitLabel');
        const allocForm    = document.getElementById('allocateBirdForm');
        if (!container || !allocForm) return;

        /* Clone the first row as a template */
        const rowTemplate  = container.querySelector('.alloc-pair-row');

        /* ── update UI counters & summary ── */
        function refreshUI() {
            const rows = container.querySelectorAll('.alloc-pair-row');
            const n    = rows.length;
            if (countBadge)  countBadge.textContent  = n === 1 ? '1 pair' : n + ' pairs';
            if (submitLabel) submitLabel.textContent  = n === 1 ? 'Allocate Bird' : `Allocate ${n} Birds`;

            /* show/hide remove buttons - never remove last row */
            rows.forEach((row, i) => {
                const btn = row.querySelector('.remove-alloc-row');
                if (btn) btn.style.display = n > 1 ? '' : 'none';
            });

            /* summary of selected pairs */
            const pairs = [];
            rows.forEach(row => {
                const bSel = row.querySelector('.alloc-bird-sel');
                const ySel = row.querySelector('.alloc-youth-sel');
                const bTxt = bSel?.selectedOptions[0]?.dataset.no || '';
                const yTxt = ySel?.selectedOptions[0]?.dataset.name || '';
                if (bTxt && yTxt) pairs.push(bTxt + ' → ' + yTxt);
            });
            if (summaryBox && summaryText) {
                if (pairs.length > 0) {
                    summaryBox.style.display = '';
                    summaryText.innerHTML    = '<strong>Ready to allocate:</strong> ' +
                        pairs.map(p => `<span class="badge badge-primary" style="margin:2px 4px 2px 0">${esc(p)}</span>`).join('');
                } else {
                    summaryBox.style.display = 'none';
                }
            }
        }

        /* ── escape helper (local copy) ── */
        const esc = v => String(v ?? '').replace(/[&<>'"]/g,
            c => ({ '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;' }[c]));

        /* ── highlight used birds/youth so they can't be double-picked ── */
        function markUsed() {
            const usedBirds  = new Set();
            const usedYouth  = new Set();
            container.querySelectorAll('.alloc-pair-row').forEach(row => {
                const bv = row.querySelector('.alloc-bird-sel')?.value;
                const yv = row.querySelector('.alloc-youth-sel')?.value;
                if (bv) usedBirds.add(bv);
                if (yv) usedYouth.add(yv);
            });
            container.querySelectorAll('.alloc-pair-row').forEach(row => {
                const bSel = row.querySelector('.alloc-bird-sel');
                const ySel = row.querySelector('.alloc-youth-sel');
                const thisBird  = bSel?.value  || '';
                const thisYouth = ySel?.value  || '';
                /* disable options already selected in OTHER rows */
                if (bSel) bSel.querySelectorAll('option').forEach(opt => {
                    if (opt.value && opt.value !== thisBird && usedBirds.has(opt.value))
                        opt.disabled = true;
                    else
                        opt.disabled = false;
                });
                if (ySel) ySel.querySelectorAll('option').forEach(opt => {
                    if (opt.value && opt.value !== thisYouth && usedYouth.has(opt.value))
                        opt.disabled = true;
                    else
                        opt.disabled = false;
                });
            });
        }

        /* ── add a new pairing row ── */
        function addRow() {
            const existing = container.querySelectorAll('.alloc-pair-row');
            const totalBirds  = (rowTemplate?.querySelector('.alloc-bird-sel')?.options.length  ?? 1) - 1;
            const totalYouth  = (rowTemplate?.querySelector('.alloc-youth-sel')?.options.length ?? 1) - 1;
            if (existing.length >= totalBirds || existing.length >= totalYouth) {
                addBtn.disabled = true;
                return;
            }

            const clone = rowTemplate.cloneNode(true);
            /* reset selects in clone */
            clone.querySelectorAll('select').forEach(s => s.value = '');
            /* show remove btn */
            const rmBtn = clone.querySelector('.remove-alloc-row');
            if (rmBtn) rmBtn.style.display = '';
            container.appendChild(clone);
            markUsed();
            refreshUI();

            /* disable add button when all available slots filled */
            const after = container.querySelectorAll('.alloc-pair-row').length;
            addBtn.disabled = after >= Math.min(totalBirds, totalYouth);
        }

        /* ── delegated events on the container ── */
        container.addEventListener('change', () => { markUsed(); refreshUI(); });
        container.addEventListener('click', e => {
            const rm = e.target.closest('.remove-alloc-row');
            if (!rm) return;
            rm.closest('.alloc-pair-row')?.remove();
            /* re-enable add button */
            addBtn.disabled = false;
            markUsed();
            refreshUI();
        });

        /* add-row button */
        addBtn?.addEventListener('click', addRow);

        /* reset when modal is opened */
        document.addEventListener('click', e => {
            if (!e.target.closest('[data-open-modal="allocateBirdModal"]')) return;
            /* keep first row, remove all extras */
            container.querySelectorAll('.alloc-pair-row:not(:first-child)').forEach(r => r.remove());
            container.querySelectorAll('.alloc-pair-row select').forEach(s => s.value = '');
            addBtn.disabled = false;
            markUsed();
            refreshUI();
        });

        /* ── form submit ── */
        allocForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            /* client-side validation */
            const rows = container.querySelectorAll('.alloc-pair-row');
            const pairs = [];
            let valid = true;
            rows.forEach((row, idx) => {
                const bv = row.querySelector('.alloc-bird-sel')?.value;
                const yv = row.querySelector('.alloc-youth-sel')?.value;
                if (!bv || !yv) { valid = false; return; }
                pairs.push({ bird_id: bv, youth_id: yv });
            });
            if (!valid || !pairs.length) {
                showAlert(false, 'Please select a bird and a youth for every row.');
                return;
            }

            const btn     = document.getElementById('allocSubmitBtn');
            const oldHTML = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; }

            try {
                /* Build FormData manually so we can send arrays */
                const fd = new FormData(allocForm);
                /* Remove any stale bird_ids[]/youth_ids[] the form may have collected
                   from disabled options - rebuild from our validated pairs array */
                fd.delete('bird_ids[]');
                fd.delete('youth_ids[]');
                pairs.forEach(p => {
                    fd.append('bird_ids[]',  p.bird_id);
                    fd.append('youth_ids[]', p.youth_id);
                });

                const res  = await fetch(ENDPOINT, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                showAlert(!!data.success, data.message);
                if (data.success) setTimeout(() => location.reload(), 1000);
            } catch { showAlert(false, 'Invalid server response.'); }
            finally  { if (btn) { btn.disabled = false; btn.innerHTML = oldHTML; } }
        });

        /* initial render */
        refreshUI();

    })(); /* end multi-allocation UI */

    /* ── ESC to close ── */
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.birds-modal.show').forEach(closeModal);
    });

})();
</script>

<?php require_once '../includes/footer.php'; ?>