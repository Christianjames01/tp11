    <?php
    $page_title = 'My Orders';
    require_once __DIR__ . '/../includes/header.php';
    requireLogin();

    $pdo    = getDBConnection();
    $userId = $_SESSION['user_id'];
    $role   = $_SESSION['role'];

    // ── Detect optional orders columns ───────────────────────────────────────────
    $orderCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    $hasTxFee  = in_array('transaction_fee', $orderCols);
    $txFeeCol  = $hasTxFee ? 'o.transaction_fee' : '0 as transaction_fee';

    // ── Filters ───────────────────────────────────────────────────────────────────
    $statusFilter = sanitize($_GET['status'] ?? '');
    $page         = max(1, intval($_GET['page'] ?? 1));
    $perPage      = 12;
    $offset       = ($page - 1) * $perPage;

    // ── Build query ───────────────────────────────────────────────────────────────
    if ($role === 'farmer') {
        $ownerWhere  = "o.farmer_id = ?";
        $joinClause  = "JOIN users u_other ON o.buyer_id  = u_other.id";
        $otherLabel  = 'Buyer';
    } else {
        $ownerWhere  = "o.buyer_id = ?";
        $joinClause  = "JOIN users u_other ON o.farmer_id = u_other.id";
        $otherLabel  = 'Farmer';
    }

    $extraWhere = $statusFilter ? "AND o.status = " . $pdo->quote($statusFilter) : '';

    // Count
    $totalCount = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $ownerWhere $extraWhere");
    $totalCount->execute([$userId]);
    $totalOrders = $totalCount->fetchColumn();
    $totalPages  = ceil($totalOrders / $perPage);

    // Fetch
    $hasDeliveryCol  = in_array('delivery_fee',  $orderCols);
    $hasDistanceCol  = in_array('distance_km',   $orderCols);
    $delivFeeSelect  = $hasDeliveryCol ? 'o.delivery_fee' : '0 as delivery_fee';
    $distSelect      = $hasDistanceCol ? 'o.distance_km'  : 'NULL as distance_km';

    $sql = "
        SELECT o.*, $txFeeCol, $delivFeeSelect, $distSelect, u_other.name as other_party_name
        FROM orders o
        $joinClause
        WHERE $ownerWhere $extraWhere
        ORDER BY o.is_priority DESC, o.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll();

    // ── Status counts for tabs ────────────────────────────────────────────────────
    $ownerWhereNoAlias = str_replace('o.', '', $ownerWhere);
    $countStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE $ownerWhereNoAlias GROUP BY status");
    $countStmt->execute([$userId]);
    $statusCounts = [];
    foreach ($countStmt->fetchAll() as $row) {
        $statusCounts[$row['status']] = $row['cnt'];
    }

    // ── Summary stats ─────────────────────────────────────────────────────────────
    $totalSpentCol  = $role === 'buyer'  ? 'buyer_id'  : 'farmer_id';
    $totalRevStmt   = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE $totalSpentCol = ? AND status = 'completed'");
    $totalRevStmt->execute([$userId]);
    $totalRevenue   = $totalRevStmt->fetchColumn();

    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE $totalSpentCol = ? AND status = 'pending'");
    $pendingStmt->execute([$userId]);
    $pendingCount = $pendingStmt->fetchColumn();

    $completedStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE $totalSpentCol = ? AND status = 'completed'");
    $completedStmt->execute([$userId]);
    $completedCount = $completedStmt->fetchColumn();

    // ── Helpers ───────────────────────────────────────────────────────────────────
    $statuses     = ['', 'pending', 'confirmed', 'processing', 'on_delivery', 'completed', 'cancelled'];
    $statusColors = [
        'pending'     => 'status-pending',
        'confirmed'   => 'status-confirmed',
        'processing'  => 'status-processing',
        'on_delivery' => 'status-on-delivery',
        'completed'   => 'status-completed',
        'cancelled'   => 'status-cancelled',
    ];
    $statusEmoji = [
        'pending'     => '🕐',
        'confirmed'   => '✅',
        'processing'  => '📦',
        'on_delivery' => '🚚',
        'completed'   => '🎉',
        'cancelled'   => '❌',
    ];

    function ordersPageUrl(int $p): string {
        $q = $_GET; $q['page'] = $p;
        return '?' . http_build_query($q);
    }
    ?>

    <style>
    /* ── Summary tiles ── */
    .orders-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.5rem;}
    .orders-stat{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.1rem;box-shadow:var(--shadow-sm);text-align:center;}
    .orders-stat-val{font-size:1.3rem;font-weight:800;color:var(--primary);}
    .orders-stat-lbl{font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-top:2px;}

    /* ── Status tabs ── */
    .status-tabs{background:white;border-radius:var(--radius-lg);padding:.5rem;margin-bottom:1.25rem;box-shadow:var(--shadow-sm);border:1px solid var(--border);display:flex;gap:4px;flex-wrap:wrap;}
    .status-tab{padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;transition:all .2s;color:var(--text-muted);white-space:nowrap;display:inline-flex;align-items:center;gap:5px;}
    .status-tab.active{background:var(--primary);color:white;}
    .status-tab:not(.active):hover{background:var(--pale-green);color:var(--primary);}
    .tab-count{background:rgba(0,0,0,.08);border-radius:99px;padding:0 6px;font-size:.68rem;}
    .status-tab.active .tab-count{background:rgba(255,255,255,.25);}

    /* ── Order card (mobile) ── */
    .order-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.1rem;margin-bottom:.75rem;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:.85rem;transition:box-shadow .15s;}
    .order-card:hover{box-shadow:var(--shadow);}

    /* ── Fee tag ── */
    .fee-tag{display:inline-flex;align-items:center;gap:3px;background:#fff7ed;color:#ea580c;border-radius:99px;padding:1px 7px;font-size:.66rem;font-weight:800;}

    /* ── Pagination ── */
    .pagination{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;}
    .page-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:var(--radius);font-size:.78rem;font-weight:700;border:1.5px solid var(--border);background:white;color:var(--text);text-decoration:none;transition:all .15s;}
    .page-btn:hover{background:var(--pale-green);border-color:var(--primary);color:var(--primary);}
    .page-btn.active{background:var(--primary);border-color:var(--primary);color:white;}
    .page-btn.disabled{opacity:.4;pointer-events:none;}

    @media(max-width:576px){
        .orders-stat-grid{grid-template-columns:repeat(2,1fr);}
    }
    </style>

    <div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
        <div class="page-header">
            <div class="container">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h1><i class="fa-solid fa-box text-green me-2"></i>My Orders</h1>
                        <div class="page-breadcrumb">Track and manage your orders</div>
                    </div>
                    <?php if ($role === 'buyer'): ?>
                    <a href="../products/browse.php" class="btn-green" style="padding:.45rem 1rem;font-size:.82rem;">
                        <i class="fa-solid fa-store"></i> Browse Products
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="container">

            <?php
            // Flash messages
            if (!empty($_SESSION['flash'])):
                foreach ($_SESSION['flash'] as $type => $flashMsg):
            ?>
            <div style="padding:.75rem 1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.85rem;font-weight:700;
                        background:<?= $type === 'success' ? '#dcfce7' : '#fee2e2' ?>;
                        color:<?= $type === 'success' ? '#16a34a' : '#dc2626' ?>;
                        border:1px solid <?= $type === 'success' ? '#bbf7d0' : '#fecaca' ?>;
                        display:flex;align-items:center;gap:.5rem;">
                <i class="fa-solid fa-<?= $type === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
                <?= sanitize($flashMsg) ?>
            </div>
            <?php
                endforeach;
                unset($_SESSION['flash']);
            endif;
            ?>

            <!-- Summary Stats -->
            <div class="orders-stat-grid">
                <div class="orders-stat">
                    <div class="orders-stat-val"><?= $totalOrders ?></div>
                    <div class="orders-stat-lbl">Total Orders</div>
                </div>
                <div class="orders-stat">
                    <div class="orders-stat-val"><?= $completedCount ?></div>
                    <div class="orders-stat-lbl">Completed</div>
                </div>
                <div class="orders-stat">
                    <div class="orders-stat-val" style="<?= $role === 'buyer' ? 'color:#ea580c;' : '' ?>">
                        ₱<?= number_format($totalRevenue, 0) ?>
                    </div>
                    <div class="orders-stat-lbl"><?= $role === 'farmer' ? 'Earned' : 'Spent' ?></div>
                </div>
            </div>

            <!-- Status Filter Tabs -->
            <div class="status-tabs">
                <?php foreach ($statuses as $s):
                    $isActive = $statusFilter === $s;
                    $cnt      = $s ? ($statusCounts[$s] ?? 0) : array_sum($statusCounts);
                ?>
                <a href="?status=<?= $s ?>&page=1" class="status-tab <?= $isActive ? 'active' : '' ?>">
                    <?php if ($s): ?><?= $statusEmoji[$s] ?? '' ?><?php endif; ?>
                    <?= $s ? ucfirst(str_replace('_', ' ', $s)) : 'All Orders' ?>
                    <span class="tab-count"><?= $cnt ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Orders Table -->
            <?php if (empty($orders)): ?>
            <div class="gl-card">
                <div class="gl-card-body empty-state" style="text-align:center;padding:3rem 1.5rem;">
                    <div style="font-size:3rem;margin-bottom:.75rem;">📦</div>
                    <p style="font-weight:700;color:var(--text-muted);margin-bottom:1rem;">
                        <?= $statusFilter ? 'No ' . ucfirst(str_replace('_', ' ', $statusFilter)) . ' orders found.' : 'No orders yet.' ?>
                    </p>
                    <?php if ($role === 'buyer'): ?>
                    <a href="../products/browse.php" class="btn-green">
                        <i class="fa-solid fa-store"></i> Browse Products
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php else: ?>
            <div class="gl-table">
                <table>
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th><?= $otherLabel ?></th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $o):
                        $txFee = floatval($o['transaction_fee'] ?? 0);
                    ?>
<?php
// Fetch buyer premium status for farmer view — must be BEFORE <tr>
$isBuyerPremiumRow = false;
if ($role === 'farmer') {
    $bpStmt = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id = ?");
    $bpStmt->execute([$o['buyer_id']]);
    $bpRow = $bpStmt->fetch();
    $isBuyerPremiumRow = !empty($bpRow['is_premium']) && !empty($bpRow['premium_until']) && strtotime($bpRow['premium_until']) > time();
}
?>
<tr style="<?= $isBuyerPremiumRow ? 'background:linear-gradient(135deg,#eff6ff,#dbeafe);' : (!empty($o['is_priority']) ? 'background:#eff6ff;' : '') ?>">
                    <td>
                        <strong style="color:var(--primary);">#<?= $o['id'] ?></strong>
                        <?php if (!empty($o['is_priority'])): ?>
                        <div style="font-size:.58rem;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white;border-radius:99px;padding:1px 7px;font-weight:800;margin-top:2px;display:inline-block;letter-spacing:.03em;">⚡ PRIORITY</div>
                        <?php endif; ?>
                    </td>
<td>
    <div style="display:flex;align-items:center;gap:8px;">
        <?php
        if ($role === 'farmer') {
            $partyId = $o['buyer_id'];
        } else {
            $partyId = $o['farmer_id'];
        }
                            $partyStmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                            $partyStmt->execute([$partyId]);
                            $partyUser = $partyStmt->fetch();
                            $partyImg  = $partyUser['profile_image'] ?? null;
                            ?>
                            <?php if ($partyImg): ?>
                                <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($partyImg) ?>"
                                    style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid var(--pale-green);flex-shrink:0;"
                                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div style="width:32px;height:32px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:none;align-items:center;justify-content:center;font-size:.68rem;font-weight:800;flex-shrink:0;">
                                    <?= strtoupper(substr($o['other_party_name'], 0, 1)) ?>
                                </div>
                            <?php else: ?>
                                <div style="width:32px;height:32px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:800;flex-shrink:0;">
                                    <?= strtoupper(substr($o['other_party_name'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        <div>
        <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
    <span style="font-weight:700;font-size:.88rem;color:<?= $isBuyerPremiumRow ? '#1d4ed8' : 'var(--text)' ?>;"><?= sanitize($o['other_party_name']) ?></span>
    <?php if ($isBuyerPremiumRow): ?>
    <span style="display:inline-flex;align-items:center;gap:3px;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white;font-size:.55rem;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.04em;">⭐ PREMIUM</span>
    <?php endif; ?>
</div>
<div style="font-size:.68rem;color:var(--text-muted);"><?= $otherLabel ?></div>
        <?php if ($role === 'buyer'): ?>
    <?php
    $premBadge = $pdo->prepare("SELECT is_premium, premium_until FROM farmers WHERE user_id = ?");
    $premBadge->execute([$o['farmer_id']]);
    $premBadgeRow = $premBadge->fetch();
    if ($premBadgeRow && !empty($premBadgeRow['is_premium']) && !empty($premBadgeRow['premium_until']) && strtotime($premBadgeRow['premium_until']) > time()):
    ?>
    <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.55rem;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.04em;display:inline-block;margin-top:2px;">⭐ PREMIUM</span>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($role === 'farmer' && !empty($o['is_priority'])): ?>
    <span style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white;font-size:.55rem;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.04em;display:inline-block;margin-top:2px;">✅ VERIFIED BUYER</span>
    <?php endif; ?>
    </div>
                        </div>
                    </td>
                        <td>
                            <strong style="color:var(--primary);">₱<?= number_format($o['total_amount'], 2) ?></strong>
                            <?php
                            $oDelivery = floatval($o['delivery_fee'] ?? 0);
                            $oDistance = $o['distance_km'] ? floatval($o['distance_km']) : null;
                            if ($oDelivery > 0): ?>
                            <div style="font-size:.68rem;color:#3b82f6;font-weight:700;margin-top:2px;display:flex;align-items:center;gap:3px;">
                                <i class="fa-solid fa-truck" style="font-size:.6rem;"></i>
                                +₱<?= number_format($oDelivery, 2) ?> delivery
                                <?php if ($oDistance): ?>
                                <span style="color:#94a3b8;font-weight:600;">(<?= number_format($oDistance,1) ?>km)</span>
                                <?php endif; ?>
                            </div>
                            <?php elseif ($oDistance): ?>
                            <div style="font-size:.68rem;color:#16a34a;font-weight:700;margin-top:2px;display:flex;align-items:center;gap:3px;">
                                <i class="fa-solid fa-truck" style="font-size:.6rem;"></i>
                                Free delivery
                                <span style="color:#94a3b8;font-weight:600;">(<?= number_format($oDistance,1) ?>km)</span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?= $statusColors[$o['status']] ?? '' ?>">
                                <?= $statusEmoji[$o['status']] ?? '' ?> <?= ucfirst(str_replace('_', ' ', $o['status'])) ?>
                            </span>
                        </td>
                        <td style="color:var(--text-muted);font-size:.82rem;white-space:nowrap;">
                            <?= date('M j, Y', strtotime($o['created_at'])) ?><br>
                            <span style="font-size:.72rem;"><?= date('g:i a', strtotime($o['created_at'])) ?></span>
                        </td>
                        <td>
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="detail.php?id=<?= $o['id'] ?>"
                                style="color:var(--primary);font-size:.82rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                                    View →
                                </a>
                                <?php if ($role === 'farmer' && $o['status'] === 'pending'): ?>
                                <a href="update_status.php?id=<?= $o['id'] ?>&status=confirmed"
                                class="status-badge status-confirmed" style="text-decoration:none;font-size:.7rem;">
                                    Confirm
                                </a>
                                <?php endif; ?>
                                <?php if (in_array($o['status'], ['pending', 'confirmed'])): ?>
                                <a href="update_status.php?id=<?= $o['id'] ?>&status=cancelled"
                                onclick="return confirm('Cancel this order?')"
                                class="status-badge status-cancelled" style="text-decoration:none;font-size:.7rem;">
                                    Cancel
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;flex-wrap:wrap;gap:.5rem;">
                <span style="font-size:.78rem;color:var(--text-muted);">
                    Showing <?= (($page-1)*$perPage)+1 ?>–<?= min($page*$perPage,$totalOrders) ?>
                    of <?= $totalOrders ?> orders
                </span>
                <div class="pagination">
                    <a href="<?= ordersPageUrl($page-1) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">
                        <i class="fa-solid fa-chevron-left" style="font-size:.65rem;"></i>
                    </a>
                    <?php for ($i=1;$i<=$totalPages;$i++): ?>
                    <a href="<?= ordersPageUrl($i) ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <a href="<?= ordersPageUrl($page+1) ?>" class="page-btn <?= $page>=totalPages?'disabled':'' ?>">
                        <i class="fa-solid fa-chevron-right" style="font-size:.65rem;"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>