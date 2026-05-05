<?php
$page_title = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo = getDBConnection();

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminId = $_SESSION['user_id'];
$admin   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin->execute([$adminId]);
$admin   = $admin->fetch();

// ── Image helper: returns a cache-busted URL or null ─────────────────────────
function userImgUrl(array $user): string|null {
    $photo = $user['profile_photo'] ?? $user['profile_image'] ?? null;
    if (empty($photo)) return null;
    $user['profile_photo'] = $photo; // normalize
    $path     = 'assets/images/profiles/' . ltrim($photo, '/');
    $fullPath = defined('BASE_PATH') ? BASE_PATH . '/' . $path : $_SERVER['DOCUMENT_ROOT'] . '/' . $path;
    $ts       = file_exists($fullPath) ? filemtime($fullPath) : (strtotime($user['updated_at'] ?? $user['created_at']) ?: time());
    return BASE_URL . '/' . htmlspecialchars($path) . '?v=' . $ts;
}

// Inline fallback: renders <img> with onerror that swaps to initials span
function userAvatar(array $user, string $size = '36px', string $fontSize = '.75rem'): string {
    $initial = strtoupper(substr($user['name'], 0, 1));
    $url     = userImgUrl($user);
    $style   = "width:{$size};height:{$size};border-radius:50%;object-fit:cover;display:block;";
    if ($url) {
        return '<img src="' . $url . '" alt="" style="' . $style . '"'
             . ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">'
             . '<span style="display:none;width:' . $size . ';height:' . $size . ';border-radius:50%;'
             . 'background:var(--pale-green);color:var(--primary);align-items:center;justify-content:center;'
             . 'font-size:' . $fontSize . ';font-weight:800;">' . $initial . '</span>';
    }
    return '<span style="display:flex;width:' . $size . ';height:' . $size . ';border-radius:50%;'
         . 'background:var(--pale-green);color:var(--primary);align-items:center;justify-content:center;'
         . 'font-size:' . $fontSize . ';font-weight:800;">' . $initial . '</span>';
}

// ── Handle Actions ────────────────────────────────────────────────────────────
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = intval($_POST['user_id'] ?? 0);

    if ($action === 'delete' && $userId) {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$userId]);
        $msg = 'User deleted successfully.';
        $msgType = 'success';
    }

    if ($action === 'toggle_status' && $userId) {
        $current = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
        $current->execute([$userId]);
        $currentStatus = $current->fetchColumn();
        $newStatus = $currentStatus ? 0 : 1;
        $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role != 'admin'")->execute([$newStatus, $userId]);
        $msg = 'User status updated.';
        $msgType = 'success';
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$roleFilter   = $_GET['role']   ?? 'all';
$searchFilter = trim($_GET['search'] ?? '');
$page         = max(1, intval($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

$where  = "WHERE u.role != 'admin'";
$params = [];

if ($roleFilter !== 'all') {
    $where  .= " AND u.role = ?";
    $params[] = $roleFilter;
}
if ($searchFilter !== '') {
    $where  .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$searchFilter%";
    $params[] = "%$searchFilter%";
}

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$countStmt->execute($params);
$totalCount = $countStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Fetch users with stats
$stmt = $pdo->prepare("
    SELECT u.*,
        (SELECT COUNT(*) FROM orders WHERE buyer_id = u.id) as order_count,
        (SELECT COUNT(*) FROM products WHERE farmer_id = u.id) as product_count,
        (SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE buyer_id = u.id AND status='completed') as total_spent,
        (SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE farmer_id = u.id AND status='completed') as total_earned
    FROM users u $where
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// ── Summary Counts ────────────────────────────────────────────────────────────
$totalFarmers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn();
$totalBuyers  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'")->fetchColumn();
$totalUsers   = $pdo->query("SELECT COUNT(*) FROM users WHERE role!='admin'")->fetchColumn();
// Check if is_active column exists; fallback gracefully
try {
    $activeUsers  = $pdo->query("SELECT COUNT(*) FROM users WHERE role!='admin' AND is_active=1")->fetchColumn();
} catch (Exception $e) {
    $activeUsers = $totalUsers;
}

$roleColors = ['farmer' => 'status-completed', 'buyer' => 'status-confirmed'];
?>

<style>
.admin-section-title{font-weight:800;font-size:.95rem;margin:0;}
.filter-bar{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;}
.filter-bar input[type="text"]{border:1px solid var(--border);border-radius:var(--radius);padding:.45rem .9rem;font-size:.82rem;outline:none;font-family:inherit;color:var(--text);min-width:200px;transition:border-color .2s;}
.filter-bar input[type="text"]:focus{border-color:var(--primary);}
.filter-pill{display:inline-flex;align-items:center;gap:5px;padding:.35rem .85rem;border-radius:99px;font-size:.75rem;font-weight:800;border:1.5px solid var(--border);background:white;color:var(--text-muted);cursor:pointer;transition:all .15s;text-decoration:none;}
.filter-pill:hover,.filter-pill.active{background:var(--primary);color:white;border-color:var(--primary);}
.user-avatar-sm{width:36px;height:36px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;flex-shrink:0;overflow:hidden;}
.user-avatar-sm img{width:100%;height:100%;object-fit:cover;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:.28rem .7rem;border-radius:var(--radius);font-size:.73rem;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;}
.action-btn-danger{background:#fee2e2;color:#dc2626;}.action-btn-danger:hover{background:#dc2626;color:white;}
.action-btn-warn{background:#fff7ed;color:#ea580c;}.action-btn-warn:hover{background:#ea580c;color:white;}
.action-btn-primary{background:var(--pale-green);color:var(--primary);}.action-btn-primary:hover{background:var(--primary);color:white;}
.pagination{display:flex;gap:.35rem;justify-content:center;margin-top:1rem;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:var(--radius);font-size:.78rem;font-weight:700;text-decoration:none;border:1.5px solid var(--border);color:var(--text-muted);transition:all .15s;}
.pagination a:hover{border-color:var(--primary);color:var(--primary);}
.pagination .pg-active{background:var(--primary);color:white;border-color:var(--primary);}
.alert-flash{padding:.75rem 1.1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.83rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:white;border-radius:var(--radius-lg);padding:1.75rem;max-width:420px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.18);transform:scale(.95);transition:transform .2s;}
.modal-overlay.open .modal-box{transform:scale(1);}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1><i class="fa-solid fa-users text-green me-2"></i>Manage Users</h1>
                    <div class="page-breadcrumb">GreenLink Innovators — <strong>User Management</strong> 👥</div>
                </div>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn-outline-green" style="padding:.45rem 1rem;font-size:.82rem;"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                    <a href="products.php" class="btn-green"><i class="fa-solid fa-seedling"></i> Products</a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">

            <!-- Sidebar -->
            <div class="col-lg-3">
                <div class="gl-sidebar">
                    <div class="gl-sidebar-header">
                        <?php $adminImgUrl = userImgUrl($admin); ?>
                        <?php if ($adminImgUrl): ?>
                            <img src="<?= $adminImgUrl ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid white;" alt="Profile"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="user-avatar" style="display:none;">A</div>
                        <?php else: ?>
                            <div class="user-avatar">A</div>
                        <?php endif; ?>
                        <div class="user-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="user-role">⚙️ Administrator</div>
                    </div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="users.php" class="active"><i class="fa-solid fa-users"></i> Manage Users</a>
                        <a href="products.php"><i class="fa-solid fa-seedling"></i> All Products</a>
                        <a href="orders.php"><i class="fa-solid fa-box"></i> All Orders</a>
                        <a href="<?= BASE_URL ?>/admin/marketprices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <div class="nav-divider"></div>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>
                    <div style="padding:1rem;border-top:1px solid var(--border);margin-top:.5rem;">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">User Summary</div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Total Users</span><strong style="font-size:.78rem;color:var(--primary);"><?= $totalUsers ?></strong></div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Farmers</span><strong style="font-size:.78rem;color:var(--primary);"><?= $totalFarmers ?></strong></div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Buyers</span><strong style="font-size:.78rem;color:var(--primary);"><?= $totalBuyers ?></strong></div>
                        <div style="display:flex;justify-content:space-between;"><span style="font-size:.78rem;color:var(--text-muted);">Active</span><strong style="font-size:.78rem;color:#16a34a;"><?= $activeUsers ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- Main -->
            <div class="col-lg-9">

                <?php if ($msg): ?>
                <div class="alert-flash alert-<?= $msgType ?>">
                    <i class="fa-solid fa-<?= $msgType==='success'?'circle-check':'circle-exclamation' ?>"></i>
                    <?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>

                <!-- Stat Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-1">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-users"></i></div>
                            <div><div class="stat-value"><?= $totalUsers ?></div><div class="stat-label">Total Users</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-2">
                        <div class="stat-card">
                            <div class="stat-icon earth"><i class="fa-solid fa-tractor"></i></div>
                            <div><div class="stat-value"><?= $totalFarmers ?></div><div class="stat-label">Farmers</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fa-solid fa-bag-shopping"></i></div>
                            <div><div class="stat-value"><?= $totalBuyers ?></div><div class="stat-label">Buyers</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-4">
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fa-solid fa-circle-check"></i></div>
                            <div><div class="stat-value"><?= $activeUsers ?></div><div class="stat-label">Active</div></div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;flex:1;">
                        <input type="text" name="search" placeholder="🔍  Search name or email…" value="<?= htmlspecialchars($searchFilter) ?>">
                        <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
                        <button type="submit" class="btn-green" style="padding:.42rem 1rem;font-size:.82rem;">Search</button>
                        <?php if ($searchFilter): ?>
                            <a href="?role=<?= $roleFilter ?>" class="btn-outline-green" style="padding:.42rem .9rem;font-size:.82rem;">Clear</a>
                        <?php endif; ?>
                    </form>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        <a href="?role=all&search=<?= urlencode($searchFilter) ?>" class="filter-pill <?= $roleFilter==='all'?'active':'' ?>">All</a>
                        <a href="?role=farmer&search=<?= urlencode($searchFilter) ?>" class="filter-pill <?= $roleFilter==='farmer'?'active':'' ?>"><i class="fa-solid fa-tractor"></i> Farmers</a>
                        <a href="?role=buyer&search=<?= urlencode($searchFilter) ?>" class="filter-pill <?= $roleFilter==='buyer'?'active':'' ?>"><i class="fa-solid fa-bag-shopping"></i> Buyers</a>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="font-weight:800;margin:0;"><i class="fa-solid fa-list text-green me-2"></i>
                        <?php if ($roleFilter !== 'all'): ?>
                            <?= ucfirst($roleFilter) ?>s
                        <?php else: ?>All Users<?php endif; ?>
                        <span style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-left:.5rem;">(<?= $totalCount ?> total)</span>
                    </h5>
                </div>

                <div class="gl-table">
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Contact</th>
                                <th><?= $roleFilter === 'farmer' ? 'Products' : ($roleFilter === 'buyer' ? 'Orders' : 'Activity') ?></th>
                                <th>Financials</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="user-avatar-sm">
                                        <?= userAvatar($u, '36px', '.75rem') ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:700;font-size:.83rem;"><?= sanitize($u['name']) ?></div>
                                        <div style="font-size:.7rem;color:var(--text-muted);">#<?= $u['id'] ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?= $roleColors[$u['role']] ?? '' ?>"><?= ucfirst($u['role']) ?></span>
                            </td>
                            <td>
                                <div style="font-size:.78rem;"><?= sanitize($u['email']) ?></div>
                                <?php if (!empty($u['phone'])): ?>
                                    <div style="font-size:.72rem;color:var(--text-muted);"><?= sanitize($u['phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'farmer'): ?>
                                    <div style="font-size:.78rem;"><strong style="color:var(--primary);"><?= $u['product_count'] ?></strong> <span style="color:var(--text-muted);">products</span></div>
                                    <div style="font-size:.72rem;color:var(--text-muted);"><?= $u['order_count'] ?> orders</div>
                                <?php else: ?>
                                    <div style="font-size:.78rem;"><strong style="color:var(--primary);"><?= $u['order_count'] ?></strong> <span style="color:var(--text-muted);">orders</span></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'farmer'): ?>
                                    <strong style="font-size:.82rem;color:var(--primary);">₱<?= number_format($u['total_earned'], 0) ?></strong>
                                    <div style="font-size:.68rem;color:var(--text-muted);">earned</div>
                                <?php else: ?>
                                    <strong style="font-size:.82rem;color:var(--primary);">₱<?= number_format($u['total_spent'], 0) ?></strong>
                                    <div style="font-size:.68rem;color:var(--text-muted);">spent</div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.75rem;color:var(--text-muted);"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
                                    <a href="view_user.php?id=<?= $u['id'] ?>" class="action-btn action-btn-primary">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                    <button onclick="confirmDelete(<?= $u['id'] ?>, '<?= addslashes(htmlspecialchars($u['name'])) ?>')" class="action-btn action-btn-danger">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-muted);">
                            <i class="fa-solid fa-users-slash" style="font-size:1.5rem;margin-bottom:.5rem;display:block;"></i>
                            No users found<?= $searchFilter ? ' for "'.htmlspecialchars($searchFilter).'"' : '' ?>.
                        </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page-1 ?>&role=<?= $roleFilter ?>&search=<?= urlencode($searchFilter) ?>"><i class="fa-solid fa-chevron-left" style="font-size:.65rem;"></i></a>
                    <?php endif; ?>
                    <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                        <?php if ($p === $page): ?>
                            <span class="pg-active"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $p ?>&role=<?= $roleFilter ?>&search=<?= urlencode($searchFilter) ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page+1 ?>&role=<?= $roleFilter ?>&search=<?= urlencode($searchFilter) ?>"><i class="fa-solid fa-chevron-right" style="font-size:.65rem;"></i></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div><!-- /col-lg-9 -->
        </div><!-- /row -->
    </div><!-- /container -->
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                <i class="fa-solid fa-trash" style="color:#dc2626;font-size:1.2rem;"></i>
            </div>
            <h5 style="font-weight:800;margin-bottom:.4rem;">Delete User?</h5>
            <p style="font-size:.83rem;color:var(--text-muted);margin:0;">You're about to permanently delete <strong id="deleteUserName"></strong>. This action cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">
            <div style="display:flex;gap:.75rem;">
                <button type="button" onclick="closeModal()" style="flex:1;padding:.6rem;border:1.5px solid var(--border);border-radius:var(--radius);background:white;font-weight:700;font-size:.83rem;cursor:pointer;font-family:inherit;">Cancel</button>
                <button type="submit" style="flex:1;padding:.6rem;border:none;border-radius:var(--radius);background:#dc2626;color:white;font-weight:800;font-size:.83rem;cursor:pointer;font-family:inherit;">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUserName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeModal() {
    document.getElementById('deleteModal').classList.remove('open');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>