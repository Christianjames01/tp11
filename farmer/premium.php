<?php
$page_title = 'Go Premium';
require_once __DIR__ . '/../includes/header.php';
requireLogin();
if ($_SESSION['role'] !== 'farmer') { header('Location: ../dashboard/index.php'); exit(); }

$pdo      = getDBConnection();
$userId   = $_SESSION['user_id'];

// Load farmer premium status
$fs = $pdo->prepare("SELECT * FROM farmers WHERE user_id=?");
$fs->execute([$userId]);
$farmer = $fs->fetch();
$isPremium = $farmer && $farmer['is_premium'] && strtotime($farmer['premium_until']) > time();

// Load payment history
$payments = $pdo->prepare("SELECT * FROM premium_payments WHERE farmer_id=? ORDER BY created_at DESC");
$payments->execute([$userId]);
$payments = $payments->fetchAll();

// Pending payment check
$hasPending = false;
foreach ($payments as $pay) {
    if ($pay['status'] === 'pending') { $hasPending = true; break; }
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $method    = trim(isset($_POST['payment_method']) ? $_POST['payment_method'] : '');
    $reference = trim(isset($_POST['reference_number']) ? $_POST['reference_number'] : '');
    $months    = intval(isset($_POST['months']) ? $_POST['months'] : 1);
    $months    = max(1, min(12, $months));

    if (!$method)    $error = 'Please select a payment method.';
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
            $filename  = 'prem_' . $userId . '_' . time() . '.' . strtolower($ext);
            $uploadDir = __DIR__ . '/../assets/images/proofs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            move_uploaded_file($_FILES['proof_image']['tmp_name'], $uploadDir . $filename);

            $amount = $months * 299;
            $stmt   = $pdo->prepare("INSERT INTO premium_payments (farmer_id, amount, payment_method, reference_number, proof_image, months) VALUES (?,?,?,?,?,?)");
           $stmt->execute([$userId, $amount, $method, $reference, $filename, $months]);
            setFlash('success', 'Payment submitted! Admin will verify within 24 hours. ⭐');
            header('Location: premium.php');
            exit();
        }
    }
}
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
<div class="page-header">
    <div class="container">
        <h1><i class="fa-solid fa-star text-green me-2"></i>Premium Seller</h1>
        <div class="page-breadcrumb"><a href="../farmer/dashboard.php">Dashboard</a> › Go Premium</div>
    </div>
</div>

<div class="container">
<?php if ($error): ?>
<div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:.85rem 1rem;margin-bottom:1.2rem;font-size:.88rem;font-weight:600;">
    <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($error) ?>
</div>
<?php endif; ?>
<?php $flash = getFlash(); if ($flash): ?>
<div style="background:<?= $flash['type']==='success'?'#F0FFF4':'#FFF5F5' ?>;border:1px solid <?= $flash['type']==='success'?'#9AE6B4':'#FED7D7' ?>;color:<?= $flash['type']==='success'?'#276749':'#C53030' ?>;border-radius:12px;padding:.85rem 1rem;margin-bottom:1.2rem;font-size:.88rem;font-weight:600;">
    <i class="fa-solid fa-circle-check me-2"></i><?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<div class="row g-4">

    <?php if ($isPremium): ?>
    <!-- PREMIUM ACTIVE: Full-width perks view -->
    <div class="col-12">

        <!-- Hero Banner -->
        <div style="background:linear-gradient(135deg,#78350f,#b45309,#d97706);border-radius:24px;padding:2rem;color:white;margin-bottom:1.5rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
            <div style="position:absolute;bottom:-60px;left:10%;width:250px;height:250px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>
            <div style="position:relative;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
                <div style="font-size:4rem;line-height:1;">⭐</div>
                <div style="flex:1;">
                    <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;opacity:.75;margin-bottom:.25rem;">Active Subscription</div>
                    <div style="font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:700;line-height:1.1;">Premium Seller</div>
                    <div style="opacity:.85;font-size:.88rem;margin-top:.35rem;">
                        Valid until <strong><?= date('F j, Y', strtotime($farmer['premium_until'])) ?></strong>
                        &nbsp;·&nbsp;
                        <?php
                        $daysLeft = ceil((strtotime($farmer['premium_until']) - time()) / 86400);
                        ?>
                        <span style="background:rgba(255,255,255,.2);border-radius:99px;padding:2px 10px;font-size:.75rem;font-weight:800;">
                            <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left
                        </span>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:.5rem;">
                    <a href="premium.php?tab=history"
                       onclick="document.getElementById('historyTab').style.display='block';document.getElementById('perksTab').style.display='none';return false;"
                       style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:white;border-radius:10px;padding:.55rem 1.1rem;font-size:.8rem;font-weight:800;text-decoration:none;white-space:nowrap;cursor:pointer;">
                        <i class="fa-solid fa-clock-rotate-left"></i> Payment History
                    </a>
                    <a href="premium.php"
                       style="display:inline-flex;align-items:center;gap:6px;background:white;color:#b45309;border-radius:10px;padding:.55rem 1.1rem;font-size:.8rem;font-weight:800;text-decoration:none;white-space:nowrap;">
                        <i class="fa-solid fa-rotate-right"></i> Renew Subscription
                    </a>
                </div>
            </div>
        </div>

        <!-- Tab Content -->
        <div id="perksTab">
            <div class="row g-3">
                <!-- Perks Grid -->
                <?php foreach ([
                    ['⭐','Premium Badge',      'Your products display an exclusive premium badge that builds buyer trust instantly.',             '#FFF7ED','#FED7AA','#C2410C'],
                    ['🔝','Search Priority',    'Your listings appear at the top of search results, getting up to 3× more visibility.',           '#EFF6FF','#BFDBFE','#1D4ED8'],
                    ['📢','Featured Section',   'Get showcased in the "Top Farmers" section on the homepage and browse pages.',                    '#F0FDF4','#BBF7D0','#15803D'],
                    ['📊','Sales Analytics',    'Access detailed analytics on your product views, order trends, and revenue performance.',          '#F5F3FF','#DDD6FE','#6D28D9'],
                    ['💬','Priority Messaging', 'Buyers see your messages first and you get a dedicated priority inbox label.',                     '#ECFEFF','#A5F3FC','#0E7490'],
                    ['🛡️','Verified Status',    'A verified seller badge is displayed across your profile, products, and order history.',           '#FFF1F2','#FFE4E6','#BE123C'],
                    ['💰','Same 5% Rate',       'Enjoy all premium perks while keeping the same competitive 5% commission on every sale.',         '#F7FEE7','#D9F99D','#4D7C0F'],
                    ['🚀','Boost Visibility',   'Your farm appears in promotional banners, email newsletters, and buyer recommendation lists.',     '#FFFBEB','#FDE68A','#B45309'],
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

            <!-- Stats bar -->
            <div style="background:linear-gradient(135deg,#052e16,#14532d);border-radius:16px;padding:1.25rem 1.5rem;margin-top:1.25rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">
                <div style="color:white;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;opacity:.65;flex-shrink:0;">Revenue Model</div>
                <?php foreach ([
                    ['⭐ Subscription','₱299 – ₱1,495'],
                    ['📦 Avg Commission','~ ₱800/mo'],
                    ['🚀 Monthly Revenue','₱1,099+'],
                ] as [$label,$val]): ?>
                <div style="display:flex;align-items:center;gap:.75rem;background:rgba(255,255,255,.08);border-radius:10px;padding:.5rem .85rem;">
                    <span style="font-size:.77rem;color:rgba(255,255,255,.8);"><?= $label ?></span>
                    <span style="font-weight:800;font-size:.88rem;color:#fbbf24;"><?= $val ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Payment History Tab (hidden by default) -->
        <div id="historyTab" style="display:none;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                <h6 style="font-weight:800;margin:0;"><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary);"></i> Payment History</h6>
                <button onclick="document.getElementById('historyTab').style.display='none';document.getElementById('perksTab').style.display='block';"
                        style="background:none;border:1.5px solid var(--border);border-radius:8px;padding:.3rem .8rem;font-size:.78rem;font-weight:700;cursor:pointer;color:var(--text-muted);">
                    ← Back to Perks
                </button>
            </div>
            <?php if (!empty($payments)): ?>
            <div class="gl-card">
                <div class="gl-card-body">
                    <?php foreach ($payments as $pay):
                        $sc = $pay['status'] === 'approved'
                            ? ['bg'=>'#dcfce7','color'=>'#16a34a','label'=>'Approved ✓']
                            : ($pay['status'] === 'rejected'
                                ? ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Rejected ✗']
                                : ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'Pending ⏳']);
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:.75rem 0;border-bottom:1px solid var(--border);">
                        <?php if ($pay['proof_image']): ?>
                        <img src="<?= BASE_URL ?>/assets/images/proofs/<?= sanitize($pay['proof_image']) ?>"
                             style="width:52px;height:52px;border-radius:10px;object-fit:cover;border:1px solid var(--border);cursor:pointer;flex-shrink:0;"
                             onclick="window.open(this.src)">
                        <?php endif; ?>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:800;font-size:.88rem;">₱<?= number_format($pay['amount'],2) ?> · <?= $pay['months'] ?> month<?= $pay['months']>1?'s':'' ?></div>
                            <div style="font-size:.73rem;color:var(--text-muted);"><?= strtoupper($pay['payment_method']) ?> · Ref: <span style="font-family:monospace;"><?= sanitize($pay['reference_number']) ?></span></div>
                            <div style="font-size:.71rem;color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($pay['created_at'])) ?></div>
                            <?php if ($pay['notes']): ?>
                            <div style="font-size:.72rem;color:#dc2626;font-weight:600;margin-top:2px;">Note: <?= sanitize($pay['notes']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;font-size:.7rem;font-weight:800;padding:4px 10px;border-radius:99px;white-space:nowrap;">
                            <?= $sc['label'] ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:2rem;color:var(--text-muted);font-size:.88rem;">No payment history yet.</div>
            <?php endif; ?>
        </div>

    </div>

    <?php else: ?>
    <!-- NOT PREMIUM: Original two-column layout -->
    <div class="col-lg-5">
        <?php if ($hasPending): ?>
        <div style="background:linear-gradient(135deg,#1e3a5f,#1d4ed8);border-radius:20px;padding:1.75rem;text-align:center;color:white;margin-bottom:1.25rem;">
            <div style="font-size:2.5rem;margin-bottom:.5rem;">⏳</div>
            <div style="font-weight:800;font-size:1.1rem;margin-bottom:.3rem;">Payment Under Review</div>
            <div style="opacity:.85;font-size:.82rem;">Admin will verify your payment within 24 hours.</div>
        </div>
        <?php else: ?>
        <div style="background:linear-gradient(135deg,#78350f,#d97706);border-radius:20px;padding:1.75rem;text-align:center;color:white;margin-bottom:1.25rem;">
            <div style="font-size:2.5rem;margin-bottom:.5rem;">⭐</div>
            <h3 style="font-family:'Playfair Display',serif;font-weight:700;margin-bottom:.3rem;">Premium Seller Badge</h3>
            <div style="font-size:1.8rem;font-weight:800;margin:.4rem 0;">₱299<span style="font-size:.9rem;font-weight:400;">/month</span></div>
            <p style="opacity:.85;font-size:.82rem;margin:0;">Boost your farm and reach more buyers</p>
        </div>
        <?php endif; ?>

        <!-- Revenue Breakdown -->
        <div style="background:linear-gradient(135deg,#052e16,#14532d);border-radius:20px;padding:1.5rem;margin-bottom:1.25rem;color:white;">
            <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;opacity:.7;margin-bottom:.75rem;">💰 GreenLink Revenue Model</div>
            <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1rem;">
                <?php foreach ([
                    ['⭐ Subscription (1–6 mo)','₱299 – ₱1,495'],
                    ['📦 Avg commission (4 sales/mo × 5%)','~ ₱800'],
                ] as [$lbl,$val]): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.08);border-radius:10px;padding:.6rem .85rem;">
                    <span style="font-size:.8rem;opacity:.85;"><?= $lbl ?></span>
                    <span style="font-weight:800;font-size:.88rem;"><?= $val ?></span>
                </div>
                <?php endforeach; ?>
                <div style="height:1px;background:rgba(255,255,255,.15);"></div>
                <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.13);border-radius:10px;padding:.65rem .85rem;border:1px solid rgba(255,255,255,.2);">
                    <span style="font-size:.82rem;font-weight:800;">🚀 Monthly Revenue / Power User</span>
                    <span style="font-weight:800;font-size:1rem;color:#fbbf24;">₱1,099+</span>
                </div>
            </div>
            <div style="font-size:.68rem;opacity:.6;line-height:1.5;">* Commission based on avg order value of ₱4,000 × 5% × 4 orders/month.</div>
        </div>

        <!-- Benefits -->
        <div class="gl-card">
            <div class="gl-card-body">
                <h6 style="font-weight:800;margin-bottom:1rem;">✨ What You Get</h6>
                <?php foreach ([
                    ['⭐','Premium badge on all your products'],
                    ['🔝','Priority in search results'],
                    ['📢','Featured in "Top Farmers" section'],
                    ['📊','Sales analytics dashboard'],
                    ['💬','Priority buyer messaging'],
                    ['🛡️','Verified seller status'],
                    ['💰','Same 5% commission rate'],
                ] as [$icon,$text]): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.85rem;font-weight:600;color:var(--text);">
                    <span style="font-size:1rem;width:22px;text-align:center;"><?= $icon ?></span> <?= $text ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if (!$hasPending): ?>
        <!-- Payment Form -->
        <div class="gl-card mb-3">
            <div class="gl-card-body">
                <h6 style="font-weight:800;margin-bottom:1.25rem;"><i class="fa-solid fa-credit-card text-green me-2"></i>Submit Payment</h6>
                <form method="POST" enctype="multipart/form-data">
                    <!-- Duration -->
                    <div style="margin-bottom:1rem;">
                        <label style="font-size:.78rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.5rem;">Duration</label>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
                            <?php foreach ([1=>299, 3=>797, 6=>1495] as $mo=>$price): ?>
                            <label style="cursor:pointer;">
                                <input type="radio" name="months" value="<?= $mo ?>" <?= $mo===1?'checked':'' ?> style="display:none;">
                                <div class="month-option" data-months="<?= $mo ?>" style="border:2px solid var(--border);border-radius:12px;padding:.7rem .5rem;text-align:center;transition:all .2s;">
                                    <div style="font-weight:800;font-size:.9rem;color:var(--text);"><?= $mo ?> mo<?= $mo>1?'s':'' ?></div>
                                    <div style="font-size:.75rem;font-weight:700;color:var(--primary);">₱<?= number_format($price) ?></div>
                                    <?php if ($mo > 1): ?>
                                    <div style="font-size:.62rem;color:#16a34a;font-weight:700;">Save <?= $mo===3?'10':'15' ?>%</div>
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
                            <div style="font-size:.83rem;">Amount: <strong style="color:#0070F0;" id="gcash_total">₱299</strong></div>
                        </div>
                        <div id="maya_info" style="display:none;background:#EBF9F1;border:1.5px solid #B3EAC9;border-radius:12px;padding:1rem;">
                            <div style="font-size:.7rem;font-weight:800;color:#00B14F;margin-bottom:.5rem;text-transform:uppercase;">Send via Maya</div>
                            <div style="font-size:.83rem;margin-bottom:3px;">Number: <strong style="font-family:monospace;">0948-797-0726</strong></div>
                            <div style="font-size:.83rem;margin-bottom:3px;">Name: <strong>GreenLink Innovators</strong></div>
                            <div style="font-size:.83rem;">Amount: <strong style="color:#00B14F;" id="maya_total">₱299</strong></div>
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
                            <i class="fa-solid fa-receipt text-green"></i> Proof of Payment
                        </label>
                        <div style="border:2px dashed var(--border);border-radius:12px;padding:1.25rem;text-align:center;cursor:pointer;background:var(--bg);"
                             onclick="document.getElementById('proofFile').click()" id="proofZone">
                            <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.5rem;color:var(--primary);display:block;margin-bottom:.3rem;"></i>
                            <div style="font-size:.82rem;font-weight:700;">Click to upload screenshot</div>
                            <div style="font-size:.7rem;color:var(--text-muted);">JPG, PNG up to 5MB</div>
                            <div id="proofName" style="font-size:.72rem;color:var(--primary);font-weight:700;margin-top:.3rem;display:none;"></div>
                        </div>
                        <input type="file" id="proofFile" name="proof_image" accept="image/*" style="display:none;" onchange="showProofName(this)">
                    </div>
                    <button type="submit" name="submit_payment" class="btn-green w-100 justify-content-center" style="padding:.9rem;font-size:.95rem;">
                        <i class="fa-solid fa-paper-plane me-2"></i>Submit Payment for Verification
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment History -->
        <?php if (!empty($payments)): ?>
        <div class="gl-card">
            <div class="gl-card-body">
                <h6 style="font-weight:800;margin-bottom:1rem;"><i class="fa-solid fa-clock-rotate-left text-green me-2"></i>Payment History</h6>
                <?php foreach ($payments as $pay):
                    $sc = $pay['status'] === 'approved'
                        ? ['bg'=>'#dcfce7','color'=>'#16a34a','label'=>'Approved ✓']
                        : ($pay['status'] === 'rejected'
                            ? ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Rejected ✗']
                            : ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'Pending ⏳']);
                ?>
                <div style="display:flex;align-items:center;gap:12px;padding:.75rem 0;border-bottom:1px solid var(--border);">
                    <?php if ($pay['proof_image']): ?>
                    <img src="<?= BASE_URL ?>/assets/images/proofs/<?= sanitize($pay['proof_image']) ?>"
                         style="width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--border);cursor:pointer;"
                         onclick="window.open(this.src)">
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:800;font-size:.85rem;">₱<?= number_format($pay['amount'],2) ?> · <?= $pay['months'] ?> month<?= $pay['months']>1?'s':'' ?></div>
                        <div style="font-size:.72rem;color:var(--text-muted);"><?= strtoupper($pay['payment_method']) ?> · Ref: <span style="font-family:monospace;"><?= sanitize($pay['reference_number']) ?></span></div>
                        <div style="font-size:.7rem;color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($pay['created_at'])) ?></div>
                        <?php if ($pay['notes']): ?>
                        <div style="font-size:.72rem;color:#dc2626;font-weight:600;margin-top:2px;">Note: <?= sanitize($pay['notes']) ?></div>
                        <?php endif; ?>
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

        <!-- Payment History -->
        <?php if (!empty($payments)): ?>
        <div class="gl-card">
            <div class="gl-card-body">
                <h6 style="font-weight:800;margin-bottom:1rem;"><i class="fa-solid fa-clock-rotate-left text-green me-2"></i>Payment History</h6>
                <?php foreach ($payments as $pay):
                    $sc = $pay['status'] === 'approved'
                        ? ['bg'=>'#dcfce7','color'=>'#16a34a','label'=>'Approved ✓']
                        : ($pay['status'] === 'rejected'
                            ? ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Rejected ✗']
                            : ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'Pending ⏳']);
                ?>
                <div style="display:flex;align-items:center;gap:12px;padding:.75rem 0;border-bottom:1px solid var(--border);">
                    <?php if ($pay['proof_image']): ?>
                    <img src="<?= BASE_URL ?>/assets/images/proofs/<?= sanitize($pay['proof_image']) ?>"
                         style="width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--border);cursor:pointer;"
                         onclick="window.open(this.src)">
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:800;font-size:.85rem;">₱<?= number_format($pay['amount'],2) ?> · <?= $pay['months'] ?> month<?= $pay['months']>1?'s':'' ?></div>
                        <div style="font-size:.72rem;color:var(--text-muted);"><?= strtoupper($pay['payment_method']) ?> · Ref: <span style="font-family:monospace;"><?= sanitize($pay['reference_number']) ?></span></div>
                        <div style="font-size:.7rem;color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($pay['created_at'])) ?></div>
                        <?php if ($pay['notes']): ?>
                        <div style="font-size:.72rem;color:#dc2626;font-weight:600;margin-top:2px;">Note: <?= sanitize($pay['notes']) ?></div>
                        <?php endif; ?>
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
</div>
</div>
</div>

<script>
const prices = {1: 299, 3: 797, 6: 1495};
let currentMonths = 1;
let currentMethod = '';

document.querySelectorAll('input[name="months"]').forEach(function(r) {
    r.addEventListener('change', function() {
        currentMonths = parseInt(this.value);
        document.querySelectorAll('.month-option').forEach(function(el) {
            el.style.borderColor = 'var(--border)';
            el.style.background  = 'white';
        });
        var opt = document.querySelector('.month-option[data-months="' + currentMonths + '"]');
        if (opt) { opt.style.borderColor = 'var(--primary)'; opt.style.background = 'var(--pale-green)'; }
        updateTotal();
    });
});

// Select first month by default
var firstOpt = document.querySelector('.month-option[data-months="1"]');
if (firstOpt) { firstOpt.style.borderColor = 'var(--primary)'; firstOpt.style.background = 'var(--pale-green)'; }

function updateTotal() {
    var total = '₱' + prices[currentMonths].toLocaleString();
    var g = document.getElementById('gcash_total');
    var m = document.getElementById('maya_total');
    if (g) g.textContent = total;
    if (m) m.textContent = total;
}

function selectMethod(method) {
    currentMethod = method;
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
    dz.style.borderColor = 'var(--primary)';
    dz.style.background  = 'var(--pale-green)';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>