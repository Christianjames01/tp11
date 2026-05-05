<?php
$page_title = 'Premium Payments';
require_once __DIR__ . '/../includes/header.php';
requireLogin();
if ($_SESSION['role'] !== 'admin') { header('Location: ../dashboard/index.php'); exit(); }

$pdo = getDBConnection();

// Handle approve/reject/terminate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payId   = intval($_POST['pay_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $notes   = trim($_POST['notes'] ?? '');
    $payType = $_POST['pay_type'] ?? 'farmer'; // 'farmer' or 'buyer'

    // Handle terminate action (no pay_id needed, only user_id + pay_type)
    if ($action === 'terminate') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId) {
            if ($payType === 'buyer') {
                $pdo->prepare("UPDATE users SET is_premium=0, premium_until=NULL WHERE id=?")
                    ->execute([$userId]);
            } else {
                $pdo->prepare("UPDATE farmers SET is_premium=0, premium_until=NULL WHERE user_id=?")
                    ->execute([$userId]);
            }
            setFlash('success', 'Premium access has been terminated.');
        }
        header('Location: premium_payments.php'); exit();
    }

    if ($payId && in_array($action, ['approve','reject'])) {

        if ($payType === 'buyer') {
            $pay = $pdo->prepare("SELECT * FROM buyer_premium_payments WHERE id=?");
            $pay->execute([$payId]);
            $pay = $pay->fetch();

            if ($pay) {
                if ($action === 'approve') {
                    $userRow = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
                    $userRow->execute([$pay['buyer_id']]);
                    $userRow = $userRow->fetch();

                    $base = ($userRow && !empty($userRow['is_premium']) && strtotime($userRow['premium_until']) > time())
                        ? $userRow['premium_until']
                        : date('Y-m-d H:i:s');
                    $until = date('Y-m-d H:i:s', strtotime('+' . $pay['months'] . ' months', strtotime($base)));

                    $pdo->prepare("UPDATE users SET is_premium=1, premium_until=? WHERE id=?")
                        ->execute([$until, $pay['buyer_id']]);
                    $pdo->prepare("UPDATE buyer_premium_payments SET status='approved', notes=? WHERE id=?")
                        ->execute([$notes, $payId]);
                    setFlash('success', 'Buyer payment approved! Buyer is now Premium. 💼');
                } else {
                    $pdo->prepare("UPDATE buyer_premium_payments SET status='rejected', notes=? WHERE id=?")
                        ->execute([$notes, $payId]);
                    setFlash('error', 'Buyer payment rejected.');
                }
            }

        } else {
            $pay = $pdo->prepare("SELECT * FROM premium_payments WHERE id=?");
            $pay->execute([$payId]);
            $pay = $pay->fetch();

            if ($pay) {
                if ($action === 'approve') {
                    $until = date('Y-m-d H:i:s', strtotime('+' . $pay['months'] . ' months'));
                    $pdo->prepare("UPDATE farmers SET is_premium=1, premium_until=? WHERE user_id=?")
                        ->execute([$until, $pay['farmer_id']]);
                    $pdo->prepare("UPDATE premium_payments SET status='approved', reviewed_by=?, reviewed_at=NOW(), notes=? WHERE id=?")
                        ->execute([$_SESSION['user_id'], $notes, $payId]);
                    setFlash('success', 'Payment approved! Farmer is now Premium. ⭐');
                } else {
                    $pdo->prepare("UPDATE premium_payments SET status='rejected', reviewed_by=?, reviewed_at=NOW(), notes=? WHERE id=?")
                        ->execute([$_SESSION['user_id'], $notes, $payId]);
                    setFlash('error', 'Payment rejected.');
                }
            }
        }

        header('Location: premium_payments.php'); exit();
    }
}

$viewFilter = $_GET['filter'] ?? '';
$typeFilter = $_GET['type']   ?? '';
$allowed    = ['pending','approved','rejected'];
$statusWhere = in_array($viewFilter, $allowed) ? "AND pp.status = '$viewFilter'" : "";

$farmerPayments = $pdo->query("
    SELECT pp.*, u.name AS user_name, u.email AS user_email,
           f.farm_name, f.is_premium, f.premium_until,
           pp.farmer_id AS owner_id,
           'farmer' AS pay_type
    FROM premium_payments pp
    JOIN users u ON u.id = pp.farmer_id
    LEFT JOIN farmers f ON f.user_id = pp.farmer_id
    WHERE 1=1 $statusWhere
    ORDER BY FIELD(pp.status,'pending','approved','rejected'), pp.created_at DESC
")->fetchAll();

$buyerPayments = $pdo->query("
    SELECT pp.*, u.name AS user_name, u.email AS user_email,
           NULL AS farm_name, u.is_premium, u.premium_until,
           pp.buyer_id AS owner_id,
           'buyer' AS pay_type
    FROM buyer_premium_payments pp
    JOIN users u ON u.id = pp.buyer_id
    WHERE 1=1 $statusWhere
    ORDER BY FIELD(pp.status,'pending','approved','rejected'), pp.created_at DESC
")->fetchAll();

if ($typeFilter === 'farmer') {
    $allPayments = $farmerPayments;
} elseif ($typeFilter === 'buyer') {
    $allPayments = $buyerPayments;
} else {
    $allPayments = array_merge($farmerPayments, $buyerPayments);
    usort($allPayments, function($a, $b) {
        $order = ['pending'=>0,'approved'=>1,'rejected'=>2];
        $ao = $order[$a['status']] ?? 3;
        $bo = $order[$b['status']] ?? 3;
        if ($ao !== $bo) return $ao - $bo;
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}

$pendingBuyerCount  = count(array_filter($buyerPayments,  fn($p) => $p['status'] === 'pending'));
$pendingFarmerCount = count(array_filter($farmerPayments, fn($p) => $p['status'] === 'pending'));
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
<div class="page-header">
    <div class="container">
        <h1><i class="fa-solid fa-star text-green me-2"></i>Premium Payment Requests</h1>
    </div>
</div>
<div class="container">

<?php $flash = getFlash(); if ($flash): ?>
<div style="background:<?= $flash['type']==='success'?'#F0FFF4':'#FFF5F5' ?>;border:1px solid <?= $flash['type']==='success'?'#9AE6B4':'#FED7D7' ?>;color:<?= $flash['type']==='success'?'#276749':'#C53030' ?>;border-radius:12px;padding:.85rem 1rem;margin-bottom:1.2rem;font-weight:600;font-size:.88rem;">
    <?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Type Tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap;">
    <a href="premium_payments.php<?= $viewFilter ? '?filter='.$viewFilter : '' ?>"
       style="padding:.45rem 1.1rem;border-radius:99px;font-size:.78rem;font-weight:800;text-decoration:none;border:2px solid var(--border);
              background:<?= $typeFilter===''?'#1565C0':'white' ?>;color:<?= $typeFilter===''?'white':'var(--text-muted)' ?>;">
        🌐 All
    </a>
    <a href="premium_payments.php?type=farmer<?= $viewFilter ? '&filter='.$viewFilter : '' ?>"
       style="padding:.45rem 1.1rem;border-radius:99px;font-size:.78rem;font-weight:800;text-decoration:none;border:2px solid var(--border);
              background:<?= $typeFilter==='farmer'?'#d97706':'white' ?>;color:<?= $typeFilter==='farmer'?'white':'var(--text-muted)' ?>;">
        🌾 Farmers
        <?php if ($pendingFarmerCount): ?>
        <span style="background:#dc2626;color:white;border-radius:99px;font-size:.6rem;padding:1px 6px;margin-left:3px;"><?= $pendingFarmerCount ?></span>
        <?php endif; ?>
    </a>
    <a href="premium_payments.php?type=buyer<?= $viewFilter ? '&filter='.$viewFilter : '' ?>"
       style="padding:.45rem 1.1rem;border-radius:99px;font-size:.78rem;font-weight:800;text-decoration:none;border:2px solid var(--border);
              background:<?= $typeFilter==='buyer'?'#1565C0':'white' ?>;color:<?= $typeFilter==='buyer'?'white':'var(--text-muted)' ?>;">
        💼 Buyers
        <?php if ($pendingBuyerCount): ?>
        <span style="background:#dc2626;color:white;border-radius:99px;font-size:.6rem;padding:1px 6px;margin-left:3px;"><?= $pendingBuyerCount ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- Status Filter Tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <a href="premium_payments.php<?= $typeFilter ? '?type='.$typeFilter : '' ?>"
       style="padding:.4rem .9rem;border-radius:99px;font-size:.75rem;font-weight:800;text-decoration:none;border:2px solid var(--border);
              background:<?= $viewFilter===''?'var(--primary)':'white' ?>;color:<?= $viewFilter===''?'white':'var(--text-muted)' ?>;">All</a>
    <a href="premium_payments.php?<?= $typeFilter?'type='.$typeFilter.'&':'' ?>filter=pending"
       style="padding:.4rem .9rem;border-radius:99px;font-size:.75rem;font-weight:800;text-decoration:none;border:2px solid var(--border);
              background:<?= $viewFilter==='pending'?'#d97706':'white' ?>;color:<?= $viewFilter==='pending'?'white':'var(--text-muted)' ?>;">⏳ Pending</a>
    <a href="premium_payments.php?<?= $typeFilter?'type='.$typeFilter.'&':'' ?>filter=approved"
       style="padding:.4rem .9rem;border-radius:99px;font-size:.75rem;font-weight:800;text-decoration:none;border:2px solid var(--border);
              background:<?= $viewFilter==='approved'?'#16a34a':'white' ?>;color:<?= $viewFilter==='approved'?'white':'var(--text-muted)' ?>;">✓ Approved</a>
    <a href="premium_payments.php?<?= $typeFilter?'type='.$typeFilter.'&':'' ?>filter=rejected"
       style="padding:.4rem .9rem;border-radius:99px;font-size:.75rem;font-weight:800;text-decoration:none;border:2px solid var(--border);
              background:<?= $viewFilter==='rejected'?'#dc2626':'white' ?>;color:<?= $viewFilter==='rejected'?'white':'var(--text-muted)' ?>;">✗ Rejected</a>
</div>

<div class="gl-card">
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;">
<thead>
<tr style="background:var(--bg);">
    <?php foreach (['Type','User','Amount','Method','Reference','Proof','Duration','Submitted','Status','Action'] as $h): ?>
    <th style="padding:.65rem 1rem;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;"><?= $h ?></th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($allPayments as $pay):
    $sc = $pay['status'] === 'approved'
        ? ['bg'=>'#dcfce7','color'=>'#16a34a','label'=>'Approved']
        : ($pay['status'] === 'rejected'
            ? ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Rejected']
            : ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'Pending']);
    $isBuyer = $pay['pay_type'] === 'buyer';
    $isActivePremium = !empty($pay['is_premium']) && strtotime($pay['premium_until']) > time();
?>
<tr style="border-bottom:1px solid var(--border);">
    <!-- Type badge -->
    <td style="padding:.85rem 1rem;">
        <?php if ($isBuyer): ?>
        <span style="background:#EFF6FF;color:#1565C0;font-size:.65rem;font-weight:800;padding:3px 9px;border-radius:99px;white-space:nowrap;">💼 Buyer</span>
        <?php else: ?>
        <span style="background:#FFFBEB;color:#d97706;font-size:.65rem;font-weight:800;padding:3px 9px;border-radius:99px;white-space:nowrap;">🌾 Farmer</span>
        <?php endif; ?>
    </td>
    <!-- User info -->
    <td style="padding:.85rem 1rem;">
        <div style="font-weight:800;font-size:.85rem;"><?= sanitize($pay['user_name']) ?></div>
        <?php if (!$isBuyer && !empty($pay['farm_name'])): ?>
        <div style="font-size:.72rem;color:var(--text-muted);"><?= sanitize($pay['farm_name']) ?></div>
        <?php endif; ?>
        <div style="font-size:.7rem;color:var(--text-muted);"><?= sanitize($pay['user_email']) ?></div>
        <?php if ($isActivePremium): ?>
        <div style="margin-top:4px;">
            <span style="background:<?= $isBuyer?'#EFF6FF':'#FEF3C7' ?>;color:<?= $isBuyer?'#1565C0':'#d97706' ?>;font-size:.65rem;font-weight:800;padding:2px 8px;border-radius:99px;">
                <?= $isBuyer ? '💼' : '⭐' ?> Active until <?= date('M j, Y', strtotime($pay['premium_until'])) ?>
            </span>
        </div>
        <?php endif; ?>
    </td>
    <td style="padding:.85rem 1rem;font-weight:800;color:var(--primary);">₱<?= number_format($pay['amount'],2) ?></td>
    <td style="padding:.85rem 1rem;font-weight:700;text-transform:uppercase;font-size:.8rem;"><?= sanitize($pay['payment_method']) ?></td>
    <td style="padding:.85rem 1rem;font-family:monospace;font-size:.8rem;"><?= sanitize($pay['reference_number']) ?></td>
    <td style="padding:.85rem 1rem;">
        <?php if (!empty($pay['proof_image'])): ?>
        <img src="<?= BASE_URL ?>/assets/images/proofs/<?= sanitize($pay['proof_image']) ?>"
             style="width:52px;height:52px;border-radius:8px;object-fit:cover;cursor:pointer;border:1px solid var(--border);"
             onclick="window.open(this.src,'_blank')">
        <?php else: ?>
        <span style="color:var(--text-muted);font-size:.75rem;">None</span>
        <?php endif; ?>
    </td>
    <td style="padding:.85rem 1rem;font-size:.82rem;font-weight:700;"><?= $pay['months'] ?> mo<?= $pay['months']>1?'s':'' ?></td>
    <td style="padding:.85rem 1rem;font-size:.75rem;color:var(--text-muted);"><?= date('M j, Y', strtotime($pay['created_at'])) ?></td>
    <td style="padding:.85rem 1rem;">
        <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;font-size:.7rem;font-weight:800;padding:4px 10px;border-radius:99px;">
            <?= $sc['label'] ?>
        </span>
        <?php if (!empty($pay['notes'])): ?>
        <div style="font-size:.7rem;color:var(--text-muted);margin-top:3px;"><?= sanitize($pay['notes']) ?></div>
        <?php endif; ?>
    </td>
    <!-- Action column -->
    <td style="padding:.85rem 1rem;">
        <?php if ($pay['status'] === 'pending'): ?>
        <button onclick="openReview(<?= $pay['id'] ?>,'approve','<?= $pay['pay_type'] ?>')"
                style="background:#16a34a;color:white;border:none;border-radius:8px;padding:.35rem .75rem;font-size:.75rem;font-weight:800;cursor:pointer;margin-bottom:4px;display:block;width:100%;">
            ✓ Approve
        </button>
        <button onclick="openReview(<?= $pay['id'] ?>,'reject','<?= $pay['pay_type'] ?>')"
                style="background:#dc2626;color:white;border:none;border-radius:8px;padding:.35rem .75rem;font-size:.75rem;font-weight:800;cursor:pointer;display:block;width:100%;">
            ✗ Reject
        </button>
        <?php else: ?>
        <span style="font-size:.75rem;color:var(--text-muted);">Done</span>
        <?php endif; ?>

        <?php if ($isActivePremium): ?>
        <button onclick="openTerminate(<?= $pay['owner_id'] ?>,'<?= $pay['pay_type'] ?>','<?= addslashes(sanitize($pay['user_name'])) ?>')"
                style="background:#7c3aed;color:white;border:none;border-radius:8px;padding:.35rem .75rem;font-size:.75rem;font-weight:800;cursor:pointer;margin-top:4px;display:block;width:100%;">
            ⛔ Terminate
        </button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($allPayments)): ?>
<tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted);">No payment requests yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
</div>

<!-- Approve/Reject Modal -->
<div id="reviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:20px;padding:1.75rem;width:100%;max-width:420px;margin:1rem;">
        <h6 style="font-weight:800;margin-bottom:1rem;" id="reviewTitle">Review Payment</h6>
        <form method="POST">
            <input type="hidden" name="pay_id"   id="review_pay_id">
            <input type="hidden" name="action"   id="review_action">
            <input type="hidden" name="pay_type" id="review_pay_type">
            <div style="margin-bottom:1rem;">
                <label style="font-size:.78rem;font-weight:800;color:var(--text-muted);display:block;margin-bottom:.4rem;">Note (optional)</label>
                <textarea name="notes" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:.65rem;font-size:.85rem;resize:vertical;" rows="3" placeholder="Reason for rejection, or confirmation message..."></textarea>
            </div>
            <div style="display:flex;gap:.6rem;">
                <button type="button" onclick="closeReview()" style="flex:1;padding:.75rem;border-radius:12px;border:2px solid var(--border);background:white;font-weight:700;cursor:pointer;">Cancel</button>
                <button type="submit" id="reviewSubmitBtn" style="flex:2;padding:.75rem;border-radius:12px;border:none;color:white;font-weight:800;cursor:pointer;">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Terminate Premium Modal -->
<div id="terminateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:20px;padding:1.75rem;width:100%;max-width:420px;margin:1rem;">
        <div style="text-align:center;margin-bottom:1.2rem;">
            <div style="font-size:2rem;margin-bottom:.5rem;">⛔</div>
            <h6 style="font-weight:800;margin-bottom:.4rem;">Terminate Premium Access</h6>
            <p id="terminateUserLabel" style="font-size:.85rem;color:var(--text-muted);margin:0;"></p>
        </div>
        <div style="background:#FFF5F5;border:1px solid #FED7D7;border-radius:10px;padding:.75rem 1rem;margin-bottom:1.2rem;font-size:.82rem;color:#C53030;">
            ⚠️ This will immediately revoke premium access. The user will lose all premium features right away.
        </div>
        <form method="POST">
            <input type="hidden" name="action"   value="terminate">
            <input type="hidden" name="user_id"  id="terminate_user_id">
            <input type="hidden" name="pay_type" id="terminate_pay_type">
            <div style="display:flex;gap:.6rem;">
                <button type="button" onclick="closeTerminate()" style="flex:1;padding:.75rem;border-radius:12px;border:2px solid var(--border);background:white;font-weight:700;cursor:pointer;">Cancel</button>
                <button type="submit" style="flex:2;padding:.75rem;border-radius:12px;border:none;background:#7c3aed;color:white;font-weight:800;cursor:pointer;">Yes, Terminate</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReview(payId, action, payType) {
    document.getElementById('review_pay_id').value   = payId;
    document.getElementById('review_action').value   = action;
    document.getElementById('review_pay_type').value = payType;
    document.getElementById('reviewTitle').textContent = action === 'approve' ? '✓ Approve Payment' : '✗ Reject Payment';
    var btn = document.getElementById('reviewSubmitBtn');
    btn.style.background = action === 'approve' ? '#16a34a' : '#dc2626';
    btn.textContent = action === 'approve' ? 'Approve & Activate Premium' : 'Reject Payment';
    document.getElementById('reviewModal').style.display = 'flex';
}
function closeReview() {
    document.getElementById('reviewModal').style.display = 'none';
}

function openTerminate(userId, payType, userName) {
    document.getElementById('terminate_user_id').value  = userId;
    document.getElementById('terminate_pay_type').value = payType;
    document.getElementById('terminateUserLabel').textContent = 'User: ' + userName;
    document.getElementById('terminateModal').style.display = 'flex';
}
function closeTerminate() {
    document.getElementById('terminateModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>