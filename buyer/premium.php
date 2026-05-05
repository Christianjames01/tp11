<?php
ob_start(); // ← add this as the very first line
$page_title = 'Buyer Premium';
require_once __DIR__ . '/../includes/header.php';
requireLogin();
if ($_SESSION['role'] !== 'buyer') { header('Location: ../dashboard/index.php'); exit(); }

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

// Load buyer premium status from users table
$bs = $pdo->prepare("SELECT * FROM users WHERE id=?");
$bs->execute([$userId]);
$buyer = $bs->fetch();
$isPremium = $buyer && !empty($buyer['is_premium']) && strtotime($buyer['premium_until']) > time();

// Load payment history
try {
    $payments = $pdo->prepare("SELECT * FROM buyer_premium_payments WHERE buyer_id=? ORDER BY created_at DESC");
    $payments->execute([$userId]);
    $payments = $payments->fetchAll();
} catch (Exception $e) {
    $payments = [];
}

$hasPending = false;
foreach ($payments as $pay) {
    if ($pay['status'] === 'pending') { $hasPending = true; break; }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $method    = trim($_POST['payment_method'] ?? '');
    $reference = trim($_POST['reference_number'] ?? '');
    $months    = max(1, min(12, intval($_POST['months'] ?? 1)));

if ($hasPending) $error = 'You already have a payment under review. Please wait for admin approval.';
elseif (!$method)    $error = 'Please select a payment method.';
    elseif (!$reference) $error = 'Please enter your reference number.';
    elseif (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK)
        $error = 'Please upload proof of payment.';
    else {
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $_FILES['proof_image']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            $error = 'Proof must be JPG, PNG, WebP, or GIF.';
        } elseif ($_FILES['proof_image']['size'] > 5 * 1024 * 1024) {
            $error = 'Proof image must be under 5MB.';
    } else {
            $ext       = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
            $filename  = 'bprem_' . $userId . '_' . time() . '.' . strtolower($ext);
            $uploadDir = __DIR__ . '/../assets/images/proofs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            move_uploaded_file($_FILES['proof_image']['tmp_name'], $uploadDir . $filename);

            $amount = $months * 199;

            // Final duplicate guard at DB level
            $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM buyer_premium_payments WHERE buyer_id=? AND status='pending'");
            $dupCheck->execute([$userId]);
            $alreadyPending = $dupCheck->fetchColumn() > 0;

            if ($alreadyPending || $isPremium) {
                $error = 'You already have a pending payment or an active subscription.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO buyer_premium_payments (buyer_id, amount, payment_method, reference_number, proof_image, months) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$userId, $amount, $method, $reference, $filename, $months]);
                } catch (Exception $e) {
                    // table may not exist yet
                }
                setFlash('success', 'Payment submitted! Admin will verify within 24 hours. 💼');
                header('Location: premium.php');
                exit();
            }
        }
    }
}
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
<div class="page-header">
    <div class="container">
        <h1><i class="fa-solid fa-crown" style="color:#1565C0;margin-right:.5rem;"></i>Buyer Premium</h1>
        <div class="page-breadcrumb"><a href="../dashboard/index.php">Home</a> › Buyer Premium</div>
    </div>
</div>

<div class="container">

<?php if ($error): ?>
<div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:.85rem 1rem;margin-bottom:1.2rem;font-size:.88rem;font-weight:600;">
    <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($error) ?>
</div>
<?php endif; ?>

<?php $flash = getFlash(); if ($flash): ?>
<div style="background:<?= $flash['type']==='success'?'#EFF6FF':'#FFF5F5' ?>;border:1px solid <?= $flash['type']==='success'?'#BFDBFE':'#FED7D7' ?>;color:<?= $flash['type']==='success'?'#1D4ED8':'#C53030' ?>;border-radius:12px;padding:.85rem 1rem;margin-bottom:1.2rem;font-size:.88rem;font-weight:600;">
    <i class="fa-solid fa-circle-check me-2"></i><?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<div class="row g-4">

<?php if ($isPremium): ?>
<!-- ══ PREMIUM ACTIVE ══ -->
<div class="col-12">

    <!-- Hero Banner -->
    <div style="background:linear-gradient(135deg,#1565C0,#1976D2,#0D47A1);border-radius:24px;padding:2rem;color:white;margin-bottom:1.5rem;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
        <div style="position:absolute;bottom:-60px;left:10%;width:250px;height:250px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>
        <div style="position:relative;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
            <div style="font-size:4rem;line-height:1;">💼</div>
            <div style="flex:1;">
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;opacity:.75;margin-bottom:.25rem;">Active Subscription</div>
                <div style="font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:700;line-height:1.1;">Premium Buyer</div>
                <div style="opacity:.85;font-size:.88rem;margin-top:.35rem;">
                    Valid until <strong><?= date('F j, Y', strtotime($buyer['premium_until'])) ?></strong>
                    &nbsp;·&nbsp;
                    <?php $daysLeft = ceil((strtotime($buyer['premium_until']) - time()) / 86400); ?>
                    <span style="background:rgba(255,255,255,.2);border-radius:99px;padding:2px 10px;font-size:.75rem;font-weight:800;">
                        <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left
                    </span>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:.5rem;">
                <a href="premium.php"
                   style="display:inline-flex;align-items:center;gap:6px;background:white;color:#1565C0;border-radius:10px;padding:.55rem 1.1rem;font-size:.8rem;font-weight:800;text-decoration:none;white-space:nowrap;">
                    <i class="fa-solid fa-rotate-right"></i> Renew Subscription
                </a>
            </div>
        </div>
    </div>

    <!-- Perks Grid -->
    <div class="row g-3">
        <?php foreach ([
            ['⚡','Priority Order Processing',  'Your orders are flagged as high-priority — farmers fulfill them first before standard orders.',             '#EFF6FF','#BFDBFE','#1D4ED8'],
            ['🌾','Early Harvest Access',        'Reserve produce from upcoming harvests before they are publicly listed — get first pick every time.',       '#F0FDF4','#BBF7D0','#15803D'],
            ['💰','Bulk Order Discounts',         'Automatic percentage discounts applied when you order large quantities — the more you buy, the more you save.','#FFFBEB','#FDE68A','#B45309'],
            ['🔔','Price Drop Alerts',            'Get instant notifications when commodities you\'ve ordered before drop below your target price threshold.',  '#FFF1F2','#FFE4E6','#BE123C'],
            ['📋','Purchase Reports',             'Download full CSV or PDF reports of your order history — perfect for restaurant accounting and audits.',     '#F5F3FF','#DDD6FE','#6D28D9'],
            ['✅','Verified Buyer Badge',         'A trust badge displayed on your profile that signals reliability — farmers prioritize and trust verified buyers.','#ECFEFF','#A5F3FC','#0E7490'],
            ['🤝','Dedicated Account Manager',    'A GreenLink agent handles your disputes, sourcing requests, and bulk order coordination personally.',        '#FFF7ED','#FED7AA','#C2410C'],
            ['📍','Multiple Delivery Addresses',  'Save and manage multiple delivery locations — ideal for restaurants, warehouses, or multiple branches.',     '#F0FDF4','#BBF7D0','#15803D'],
        ] as [$icon,$title,$desc,$bg,$border,$color]): ?>
        <div class="col-sm-6 col-lg-3">
            <div style="background:<?= $bg ?>;border:1.5px solid <?= $border ?>;border-radius:16px;padding:1.1rem;height:100%;">
                <div style="font-size:1.6rem;margin-bottom:.5rem;"><?= $icon ?></div>
                <div style="font-weight:800;font-size:.88rem;color:<?= $color ?>;margin-bottom:.3rem;"><?= $title ?></div>
                <div style="font-size:.75rem;color:#374151;line-height:1.5;"><?= $desc ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Value bar -->
    <div style="background:linear-gradient(135deg,#0d1b3e,#1565C0);border-radius:16px;padding:1.25rem 1.5rem;margin-top:1.25rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">
        <div style="color:white;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;opacity:.65;flex-shrink:0;">Buyer Savings Model</div>
        <?php foreach ([
            ['💰 Subscription','₱199 – ₱997/mo'],
            ['📦 Avg Bulk Discount','~ ₱500 saved/order'],
            ['🚀 Monthly Savings','₱300+'],
        ] as [$label,$val]): ?>
        <div style="display:flex;align-items:center;gap:.75rem;background:rgba(255,255,255,.08);border-radius:10px;padding:.5rem .85rem;">
            <span style="font-size:.77rem;color:rgba(255,255,255,.8);"><?= $label ?></span>
            <span style="font-weight:800;font-size:.88rem;color:#93c5fd;"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Payment history -->
    <?php if (!empty($payments)): ?>
    <div class="gl-card" style="margin-top:1.25rem;">
        <div class="gl-card-body">
            <h6 style="font-weight:800;margin-bottom:1rem;"><i class="fa-solid fa-clock-rotate-left" style="color:#1565C0;margin-right:.5rem;"></i>Payment History</h6>
            <?php foreach ($payments as $pay):
                $sc = $pay['status'] === 'approved'
                    ? ['bg'=>'#dcfce7','color'=>'#16a34a','label'=>'Approved ✓']
                    : ($pay['status'] === 'rejected'
                        ? ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Rejected ✗']
                        : ['bg'=>'#dbeafe','color'=>'#1d4ed8','label'=>'Pending ⏳']);
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:.75rem 0;border-bottom:1px solid var(--border);">
                <?php if (!empty($pay['proof_image'])): ?>
                <img src="<?= BASE_URL ?>/assets/images/proofs/<?= sanitize($pay['proof_image']) ?>"
                     style="width:52px;height:52px;border-radius:10px;object-fit:cover;border:1px solid var(--border);cursor:pointer;flex-shrink:0;"
                     onclick="window.open(this.src)">
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:800;font-size:.88rem;">₱<?= number_format($pay['amount'],2) ?> · <?= $pay['months'] ?> month<?= $pay['months']>1?'s':'' ?></div>
                    <div style="font-size:.73rem;color:var(--text-muted);"><?= strtoupper($pay['payment_method']) ?> · Ref: <span style="font-family:monospace;"><?= sanitize($pay['reference_number']) ?></span></div>
                    <div style="font-size:.71rem;color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($pay['created_at'])) ?></div>
                </div>
                <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;font-size:.7rem;font-weight:800;padding:4px 10px;border-radius:99px;white-space:nowrap;">
                    <?= $sc['label'] ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php else: ?>
<!-- ══ NOT PREMIUM ══ -->

<!-- Left column -->
<div class="col-lg-5">

    <?php if ($hasPending): ?>
    <div style="background:linear-gradient(135deg,#1e3a5f,#1d4ed8);border-radius:20px;padding:1.75rem;text-align:center;color:white;margin-bottom:1.25rem;">
        <div style="font-size:2.5rem;margin-bottom:.5rem;">⏳</div>
        <div style="font-weight:800;font-size:1.1rem;margin-bottom:.3rem;">Payment Under Review</div>
        <div style="opacity:.85;font-size:.82rem;">Admin will verify your payment within 24 hours.</div>
    </div>
    <?php else: ?>
    <!-- Price card -->
    <div style="background:linear-gradient(135deg,#1565C0,#1976D2);border-radius:20px;padding:1.75rem;text-align:center;color:white;margin-bottom:1.25rem;">
        <div style="font-size:2.5rem;margin-bottom:.5rem;">💼</div>
        <h3 style="font-family:'Playfair Display',serif;font-weight:700;margin-bottom:.3rem;">Premium Buyer</h3>
        <div style="font-size:1.8rem;font-weight:800;margin:.4rem 0;">₱199<span style="font-size:.9rem;font-weight:400;">/month</span></div>
        <p style="opacity:.85;font-size:.82rem;margin:0;">Source smarter, save more, buy better</p>
    </div>
    <?php endif; ?>

    <!-- Savings model -->
    <div style="background:linear-gradient(135deg,#0d1b3e,#1565C0);border-radius:20px;padding:1.5rem;margin-bottom:1.25rem;color:white;">
        <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;opacity:.7;margin-bottom:.75rem;">💰 Buyer Savings Model</div>
        <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1rem;">
            <?php foreach ([
                ['💼 Subscription (1–5 mo)','₱199 – ₱997'],
                ['📦 Avg bulk discount saved','~ ₱500/order'],
            ] as [$lbl,$val]): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.08);border-radius:10px;padding:.6rem .85rem;">
                <span style="font-size:.8rem;opacity:.85;"><?= $lbl ?></span>
                <span style="font-weight:800;font-size:.88rem;"><?= $val ?></span>
            </div>
            <?php endforeach; ?>
            <div style="height:1px;background:rgba(255,255,255,.15);"></div>
            <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.13);border-radius:10px;padding:.65rem .85rem;border:1px solid rgba(255,255,255,.2);">
                <span style="font-size:.82rem;font-weight:800;">🚀 Monthly Net Savings</span>
                <span style="font-weight:800;font-size:1rem;color:#93c5fd;">₱300+</span>
            </div>
        </div>
        <div style="font-size:.68rem;opacity:.6;line-height:1.5;">* Based on 2 bulk orders/month with 5% discount applied.</div>
    </div>

    <!-- Benefits list -->
    <div class="gl-card">
        <div class="gl-card-body">
            <h6 style="font-weight:800;margin-bottom:1rem;">✨ What You Get</h6>
            <?php foreach ([
                ['⚡','Priority order processing — first in queue'],
                ['🌾','Early harvest access — reserve before listing'],
                ['💰','Bulk order discounts — auto-applied'],
                ['🔔','Price drop alerts — never overpay again'],
                ['📋','Purchase reports — CSV/PDF export'],
                ['✅','Verified buyer badge — earn farmer trust'],
                ['🤝','Dedicated account manager'],
                ['📍','Multiple saved delivery addresses'],
            ] as [$icon,$text]): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.85rem;font-weight:600;color:var(--text);">
                <span style="font-size:1rem;width:22px;text-align:center;"><?= $icon ?></span> <?= $text ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- Right column: Payment form -->
<div class="col-lg-7">

    <?php if (!$hasPending): ?>
    <div class="gl-card mb-3">
        <div class="gl-card-body">
            <h6 style="font-weight:800;margin-bottom:1.25rem;"><i class="fa-solid fa-credit-card" style="color:#1565C0;margin-right:.5rem;"></i>Submit Payment</h6>
            <form method="POST" enctype="multipart/form-data">

                <!-- Duration -->
                <div style="margin-bottom:1rem;">
                    <label style="font-size:.78rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.5rem;">Duration</label>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
                        <?php foreach ([1=>199, 3=>497, 5=>997] as $mo=>$price): ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="months" value="<?= $mo ?>" <?= $mo===1?'checked':'' ?> style="display:none;">
                            <div class="month-option" data-months="<?= $mo ?>" style="border:2px solid var(--border);border-radius:12px;padding:.7rem .5rem;text-align:center;transition:all .2s;">
                                <div style="font-weight:800;font-size:.9rem;color:var(--text);"><?= $mo ?> mo<?= $mo>1?'s':'' ?></div>
                                <div style="font-size:.75rem;font-weight:700;color:#1565C0;">₱<?= number_format($price) ?></div>
                                <?php if ($mo > 1): ?>
                                <div style="font-size:.62rem;color:#16a34a;font-weight:700;">Save <?= $mo===3?'17':'16' ?>%</div>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Payment Method -->
                <div style="margin-bottom:1rem;">
                    <label style="font-size:.78rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.5rem;">Payment Method</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <?php foreach (['gcash'=>['GCash','#0070F0','#F0F6FF'],'maya'=>['Maya','#00B14F','#F0FBF4']] as $key=>[$label,$color,$bg]): ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="<?= $key ?>" style="display:none;" onchange="selectMethod('<?= $key ?>')">
                            <div id="pm_<?= $key ?>" style="border:2px solid var(--border);border-radius:12px;padding:.75rem;text-align:center;transition:all .2s;">
                                <div style="font-weight:800;font-size:.9rem;color:<?= $color ?>"><?= $label ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);">Instant transfer</div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Payment Instructions -->
                <div id="payment_instructions" style="display:none;margin-bottom:1rem;">
                    <div id="gcash_info" style="display:none;background:#EEF5FF;border:1.5px solid #C5DEFF;border-radius:12px;padding:1rem;">
                        <div style="font-size:.7rem;font-weight:800;color:#0070F0;margin-bottom:.5rem;text-transform:uppercase;">Send via GCash</div>
                        <div style="font-size:.83rem;margin-bottom:3px;">Number: <strong style="font-family:monospace;">0948-797-0726</strong></div>
                        <div style="font-size:.83rem;margin-bottom:3px;">Name: <strong>GreenLink Innovators</strong></div>
                        <div style="font-size:.83rem;">Amount: <strong style="color:#0070F0;" id="gcash_total">₱199</strong></div>
                    </div>
                    <div id="maya_info" style="display:none;background:#EBF9F1;border:1.5px solid #B3EAC9;border-radius:12px;padding:1rem;">
                        <div style="font-size:.7rem;font-weight:800;color:#00B14F;margin-bottom:.5rem;text-transform:uppercase;">Send via Maya</div>
                        <div style="font-size:.83rem;margin-bottom:3px;">Number: <strong style="font-family:monospace;">0948-797-0726</strong></div>
                        <div style="font-size:.83rem;margin-bottom:3px;">Name: <strong>GreenLink Innovators</strong></div>
                        <div style="font-size:.83rem;">Amount: <strong style="color:#00B14F;" id="maya_total">₱199</strong></div>
                    </div>
                </div>

                <!-- Reference Number -->
                <div style="margin-bottom:1rem;">
                    <label style="font-size:.78rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.5rem;">Reference Number</label>
                    <div class="gl-input-wrap">
                        <i class="fa-solid fa-hashtag input-icon"></i>
                        <input type="text" name="reference_number" class="gl-input" placeholder="e.g. 1234567890123" style="font-family:monospace;">
                    </div>
                </div>

                <!-- Proof Upload -->
                <div style="margin-bottom:1.25rem;">
                    <label style="font-size:.78rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.5rem;">
                        <i class="fa-solid fa-receipt" style="color:#1565C0;"></i> Proof of Payment
                    </label>
                    <div style="border:2px dashed var(--border);border-radius:12px;padding:1.25rem;text-align:center;cursor:pointer;background:var(--bg);"
                         onclick="document.getElementById('proofFile').click()" id="proofZone">
                        <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.5rem;color:#1565C0;display:block;margin-bottom:.3rem;"></i>
                        <div style="font-size:.82rem;font-weight:700;">Click to upload screenshot</div>
                        <div style="font-size:.7rem;color:var(--text-muted);">JPG, PNG up to 5MB</div>
                        <div id="proofName" style="font-size:.72rem;color:#1565C0;font-weight:700;margin-top:.3rem;display:none;"></div>
                    </div>
                    <input type="file" id="proofFile" name="proof_image" accept="image/*" style="display:none;" onchange="showProofName(this)">
                </div>

                <button type="submit" name="submit_payment"
                        style="width:100%;padding:.9rem;font-size:.95rem;font-weight:800;border:none;border-radius:12px;background:linear-gradient(135deg,#1565C0,#1976D2);color:white;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem;">
                    <i class="fa-solid fa-paper-plane"></i> Submit Payment for Verification
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment history -->
    <?php if (!empty($payments)): ?>
    <div class="gl-card">
        <div class="gl-card-body">
            <h6 style="font-weight:800;margin-bottom:1rem;"><i class="fa-solid fa-clock-rotate-left" style="color:#1565C0;margin-right:.5rem;"></i>Payment History</h6>
            <?php foreach ($payments as $pay):
                $sc = $pay['status'] === 'approved'
                    ? ['bg'=>'#dcfce7','color'=>'#16a34a','label'=>'Approved ✓']
                    : ($pay['status'] === 'rejected'
                        ? ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Rejected ✗']
                        : ['bg'=>'#dbeafe','color'=>'#1d4ed8','label'=>'Pending ⏳']);
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:.75rem 0;border-bottom:1px solid var(--border);">
                <?php if (!empty($pay['proof_image'])): ?>
                <img src="<?= BASE_URL ?>/assets/images/proofs/<?= sanitize($pay['proof_image']) ?>"
                     style="width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--border);cursor:pointer;"
                     onclick="window.open(this.src)">
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:800;font-size:.85rem;">₱<?= number_format($pay['amount'],2) ?> · <?= $pay['months'] ?> month<?= $pay['months']>1?'s':'' ?></div>
                    <div style="font-size:.72rem;color:var(--text-muted);"><?= strtoupper($pay['payment_method']) ?> · Ref: <span style="font-family:monospace;"><?= sanitize($pay['reference_number']) ?></span></div>
                    <div style="font-size:.7rem;color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($pay['created_at'])) ?></div>
                </div>
                <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;font-size:.7rem;font-weight:800;padding:4px 10px;border-radius:99px;white-space:nowrap;">
                    <?= $sc['label'] ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

</div><!-- /row -->
</div><!-- /container -->
</div>

<script>
const prices = {1: 199, 3: 497, 5: 997};
let currentMonths = 1;

document.querySelectorAll('input[name="months"]').forEach(function(r) {
    r.addEventListener('change', function() {
        currentMonths = parseInt(this.value);
        document.querySelectorAll('.month-option').forEach(function(el) {
            el.style.borderColor = 'var(--border)';
            el.style.background  = 'white';
        });
        var opt = document.querySelector('.month-option[data-months="' + currentMonths + '"]');
        if (opt) { opt.style.borderColor = '#1565C0'; opt.style.background = '#EFF6FF'; }
        updateTotal();
    });
});

var firstOpt = document.querySelector('.month-option[data-months="1"]');
if (firstOpt) { firstOpt.style.borderColor = '#1565C0'; firstOpt.style.background = '#EFF6FF'; }

function updateTotal() {
    var total = '₱' + prices[currentMonths].toLocaleString();
    var g = document.getElementById('gcash_total');
    var m = document.getElementById('maya_total');
    if (g) g.textContent = total;
    if (m) m.textContent = total;
}

function selectMethod(method) {
    ['gcash','maya'].forEach(function(m) {
        var el = document.getElementById('pm_' + m);
        if (el) { el.style.borderColor = 'var(--border)'; el.style.background = 'white'; }
        var info = document.getElementById(m + '_info');
        if (info) info.style.display = 'none';
    });
    var sel = document.getElementById('pm_' + method);
    if (sel) { sel.style.borderColor = method === 'gcash' ? '#0070F0' : '#00B14F'; sel.style.background = method === 'gcash' ? '#F0F6FF' : '#F0FBF4'; }
    document.getElementById('payment_instructions').style.display = 'block';
    var info2 = document.getElementById(method + '_info');
    if (info2) info2.style.display = 'block';
    document.querySelector('input[name="payment_method"][value="' + method + '"]').checked = true;
}

function showProofName(input) {
    if (!input.files || !input.files[0]) return;
    var fn = document.getElementById('proofName');
    var dz = document.getElementById('proofZone');
    fn.textContent = '✅ ' + input.files[0].name;
    fn.style.display = 'block';
    dz.style.borderColor = '#1565C0';
    dz.style.background  = '#EFF6FF';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>