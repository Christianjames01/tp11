<?php
$page_title = 'Rider Profile';
require_once __DIR__ . '/../includes/header.php';
requireLogin(); // any logged-in user can view

$pdo = getDBConnection();
$riderId = intval($_GET['id'] ?? 0);

if (!$riderId) {
    header('Location: ../index.php?error=not_found'); exit();
}

// Fetch rider
$rStmt = $pdo->prepare("
    SELECT id, name, phone, location, profile_image, role, created_at
    FROM users
    WHERE id = ? AND role IN ('rider','delivery') AND is_active = 1
");
$rStmt->execute([$riderId]);
$riderUser = $rStmt->fetch();

if (!$riderUser) {
    header('Location: ../index.php?error=not_found'); exit();
}

// Vehicle info
$riderVehicle = null;
$rvCheck = $pdo->query("SHOW TABLES LIKE 'riders'")->fetchAll();
if (!empty($rvCheck)) {
    $rvStmt = $pdo->prepare("SELECT vehicle_type, plate_number FROM riders WHERE user_id = ?");
    $rvStmt->execute([$riderId]);
    $riderVehicle = $rvStmt->fetch();
}

// Stats
$delivered = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'completed'");
$delivered->execute([$riderId]);
$delivered = $delivered->fetchColumn();

$totalOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ?");
$totalOrders->execute([$riderId]);
$totalOrders = $totalOrders->fetchColumn();

$profileImageUrl = !empty($riderUser['profile_image'])
    ? BASE_URL . '/assets/images/profiles/' . htmlspecialchars($riderUser['profile_image'])
    : null;

$vehicleEmoji = [
    'motorcycle' => '🏍️', 'Motorcycle' => '🏍️',
    'scooter'    => '🛵', 'Scooter'    => '🛵',
    'bicycle'    => '🚲', 'Bicycle'    => '🚲',
    'tricycle'   => '🛺', 'Tricycle'   => '🛺',
    'van'        => '🚐', 'Van'        => '🚐',
];
$vType  = $riderVehicle['vehicle_type'] ?? '';
$vEmoji = $vehicleEmoji[$vType] ?? '🛵';

// Coords for map
$coordStmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
$coordStmt->execute([$riderId]);
$coords = $coordStmt->fetch();
$hasCoords = $coords && !empty($coords['latitude']);
?>

<style>
    .rider-pulse-wrap{position:relative;width:50px;height:50px;display:flex;align-items:center;justify-content:center;}
.rider-pulse-ring{position:absolute;width:50px;height:50px;border-radius:50%;background:rgba(249,115,22,.25);animation:riderHeartbeat 1.4s ease-out infinite;}
.rider-pulse-ring::after{content:'';position:absolute;inset:6px;border-radius:50%;background:rgba(249,115,22,.2);animation:riderHeartbeat 1.4s ease-out infinite .2s;}
.rider-pulse-dot{position:relative;z-index:2;width:36px;height:36px;border-radius:50%;background:#F97316;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 3px 12px rgba(249,115,22,.6);font-size:16px;}
@keyframes riderHeartbeat{0%{transform:scale(1);opacity:.8;}50%{transform:scale(1.5);opacity:.3;}100%{transform:scale(1.9);opacity:0;}}
.pub-wrap{background:#F0F7F0;min-height:100vh;padding-bottom:3rem;}
.pub-card{background:white;border-radius:20px;border:1px solid #E2E8F0;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;margin-bottom:1.25rem;}
.pub-card-head{padding:.85rem 1.25rem;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;gap:.5rem;}
.pub-card-head h6{margin:0;font-weight:800;font-size:.9rem;}
.pub-card-body{padding:1.25rem;}
.stat-box{background:#F8FAF8;border-radius:14px;border:1px solid #E0EDE0;padding:1rem;text-align:center;flex:1;}
.stat-val{font-size:1.6rem;font-weight:900;line-height:1;}
.stat-lbl{font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-top:4px;}
.info-row{display:flex;align-items:center;gap:10px;padding:.6rem 0;border-bottom:1px solid #F1F5F9;font-size:.85rem;}
.info-row:last-child{border-bottom:none;}
.info-row i{width:16px;text-align:center;color:#64748b;font-size:.8rem;}
</style>

<div class="pub-wrap">

    <!-- Header banner -->
    <div style="background:linear-gradient(135deg,#0D3B13 0%,#1B5E20 50%,#2E7D32 100%);padding:2rem 0 3.5rem;">
        <div class="container">
            <a href="javascript:history.back()"
               style="display:inline-flex;align-items:center;gap:6px;color:rgba(255,255,255,.75);font-size:.82rem;font-weight:700;text-decoration:none;margin-bottom:1.25rem;">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
            <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;">
                <?php if ($profileImageUrl): ?>
                    <img src="<?= $profileImageUrl ?>"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:4px solid rgba(255,255,255,.4);flex-shrink:0;">
                <?php else: ?>
                    <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;color:white;border:4px solid rgba(255,255,255,.3);flex-shrink:0;">
                        <?= strtoupper(substr($riderUser['name'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <div style="font-size:1.6rem;font-weight:800;color:white;line-height:1.1;">
                        <?= sanitize($riderUser['name']) ?>
                    </div>
                    <div style="font-size:.85rem;color:rgba(255,255,255,.7);margin-top:.3rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span>🛵 Delivery Rider</span>
                        <?php if (!empty($riderUser['location'])): ?>
                        <span>· 📍 <?= sanitize($riderUser['location']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top:.6rem;">
                        <span style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:99px;padding:3px 12px;font-size:.72rem;font-weight:800;color:white;">
                            ✅ Active Rider
                        </span>
                        <span style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:99px;padding:3px 12px;font-size:.72rem;font-weight:800;color:rgba(255,255,255,.8);margin-left:6px;">
                            Member since <?= date('M Y', strtotime($riderUser['created_at'])) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container" style="margin-top:-2rem;">
        <div class="row g-4">

            <!-- LEFT -->
            <div class="col-lg-4">

                <!-- Stats -->
                <div class="pub-card">
                    <div class="pub-card-head">
                        <i class="fa-solid fa-chart-simple" style="color:#1B5E20;"></i>
                        <h6>Delivery Stats</h6>
                    </div>
                    <div class="pub-card-body">
                        <div style="display:flex;gap:.75rem;margin-bottom:.75rem;">
                            <div class="stat-box">
                                <div class="stat-val" style="color:#16A34A;"><?= $delivered ?></div>
                                <div class="stat-lbl">Delivered</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-val" style="color:#4F46E5;"><?= $totalOrders ?></div>
                                <div class="stat-lbl">Total Orders</div>
                            </div>
                        </div>
                        <?php if ($totalOrders > 0): ?>
                        <div style="background:#F0FDF4;border-radius:10px;padding:.65rem 1rem;border:1px solid #BBF7D0;text-align:center;">
                            <span style="font-size:.8rem;font-weight:800;color:#15803d;">
                                <?= $totalOrders > 0 ? round(($delivered / $totalOrders) * 100) : 0 ?>% completion rate
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Vehicle Info -->
                <?php if ($riderVehicle && (!empty($riderVehicle['vehicle_type']) || !empty($riderVehicle['plate_number']))): ?>
                <div class="pub-card">
                    <div class="pub-card-head">
                        <i class="fa-solid fa-motorcycle" style="color:#1B5E20;"></i>
                        <h6>Vehicle Details</h6>
                    </div>
                    <div class="pub-card-body">
                        <?php if (!empty($riderVehicle['vehicle_type'])): ?>
                        <div class="info-row">
                            <i class="fa-solid fa-motorcycle"></i>
                            <div>
                                <div style="font-size:.72rem;color:#64748b;font-weight:700;">Vehicle Type</div>
                                <div style="font-weight:800;"><?= $vEmoji ?> <?= sanitize(ucfirst($riderVehicle['vehicle_type'])) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($riderVehicle['plate_number'])): ?>
                        <div class="info-row">
                            <i class="fa-solid fa-id-card"></i>
                            <div>
                                <div style="font-size:.72rem;color:#64748b;font-weight:700;">Plate / ID Number</div>
                                <div style="font-weight:800;font-family:monospace;font-size:1rem;background:#F1F5F9;display:inline-block;padding:2px 10px;border-radius:6px;letter-spacing:.08em;">
                                    <?= sanitize(strtoupper($riderVehicle['plate_number'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contact -->
                <div class="pub-card">
                    <div class="pub-card-head">
                        <i class="fa-solid fa-address-card" style="color:#1B5E20;"></i>
                        <h6>Contact</h6>
                    </div>
                    <div class="pub-card-body">
                        <?php if (!empty($riderUser['phone'])): ?>
                        <div class="info-row">
                            <i class="fa-solid fa-phone"></i>
                            <span><?= sanitize($riderUser['phone']) ?></span>
                            <a href="tel:<?= sanitize($riderUser['phone']) ?>"
                               style="margin-left:auto;background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:8px;padding:3px 10px;font-size:.72rem;font-weight:800;text-decoration:none;">
                                <i class="fa-solid fa-phone fa-xs"></i> Call
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($riderUser['location'])): ?>
                        <div class="info-row">
                            <i class="fa-solid fa-location-dot"></i>
                            <span><?= sanitize($riderUser['location']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- RIGHT -->
            <div class="col-lg-8">

                <!-- Live location map -->
                <?php if ($hasCoords): ?>
                <div class="pub-card">
                 <div class="pub-card-head" style="flex-wrap:wrap;gap:.5rem;">
                        <i class="fa-solid fa-map-pin" style="color:#1B5E20;"></i>
                        <h6>Last Known Location</h6>
                        <div style="margin-left:auto;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                            <?php if (!empty($riderVehicle['vehicle_type'])): ?>
                            <span style="background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;border-radius:99px;padding:2px 10px;font-size:.65rem;font-weight:800;">
                                <?= $vEmoji ?> <?= sanitize(ucfirst($riderVehicle['vehicle_type'])) ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($riderVehicle['plate_number'])): ?>
                            <span style="background:#f1f5f9;color:#1e293b;border:1px solid #cbd5e1;border-radius:99px;padding:2px 10px;font-size:.65rem;font-weight:800;font-family:monospace;letter-spacing:.06em;">
                                <?= sanitize(strtoupper($riderVehicle['plate_number'])) ?>
                            </span>
                            <?php endif; ?>
                            <span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:99px;padding:2px 10px;font-size:.65rem;font-weight:800;">
                                🟢 Live
                            </span>
                        </div>
                    </div>
                    <div class="pub-card-body">
                        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
                        <div id="riderPubMap" style="height:300px;border-radius:12px;border:1.5px solid #BBF7D0;"></div>
                        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                      <?php
                        // Fetch the current buyer's coordinates
                        $viewerStmt = $pdo->prepare("SELECT latitude, longitude, name FROM users WHERE id = ?");
                        $viewerStmt->execute([$_SESSION['user_id']]);
                        $viewerCoords = $viewerStmt->fetch();
                        $hasViewer = $viewerCoords && !empty($viewerCoords['latitude']);
                        ?>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const rLat = <?= floatval($coords['latitude']) ?>;
                            const rLng = <?= floatval($coords['longitude']) ?>;

                            <?php if ($hasViewer): ?>
                            const bLat = <?= floatval($viewerCoords['latitude']) ?>;
                            const bLng = <?= floatval($viewerCoords['longitude']) ?>;
                            const midLat = (rLat + bLat) / 2;
                            const midLng = (rLng + bLng) / 2;
                            const map = L.map('riderPubMap').setView([midLat, midLng], 9);
                            <?php else: ?>
                            const map = L.map('riderPubMap').setView([rLat, rLng], 14);
                            <?php endif; ?>

                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '© OpenStreetMap'
                            }).addTo(map);

                            // Rider marker with pulse
                            const riderIcon = L.divIcon({
                                html: '<div class="rider-pulse-wrap"><div class="rider-pulse-ring"></div><div class="rider-pulse-dot">🛵</div></div>',
                                className: '', iconSize:[50,50], iconAnchor:[25,25], popupAnchor:[0,-28]
                            });
                            L.marker([rLat, rLng], {icon: riderIcon, zIndexOffset: 1000})
                                .addTo(map)
                                .bindPopup('<strong>🛵 <?= addslashes(sanitize($riderUser['name'])) ?></strong><br><small>Rider location</small>')
                                .openPopup();

                            <?php if ($hasViewer): ?>
                            // Buyer marker
                            const buyerIcon = L.divIcon({
                                html: '<div style="background:#3b82f6;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:16px;">🛒</div>',
                                className: '', iconSize:[36,36], iconAnchor:[18,18], popupAnchor:[0,-20]
                            });
                            L.marker([bLat, bLng], {icon: buyerIcon})
                                .addTo(map)
                                .bindPopup('<strong>🛒 Your Location</strong><br><small><?= addslashes(sanitize($viewerCoords['name'])) ?></small>');

                            // Orange line from rider to buyer
                            L.polyline([[rLat, rLng],[bLat, bLng]], {
                                color: '#F97316', weight: 2.5, dashArray: '6,4', opacity: .8
                            }).addTo(map);

                            map.fitBounds([[rLat, rLng],[bLat, bLng]], {padding:[40,40]});
                            <?php endif; ?>
                        });
                        </script>
                      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.6rem;padding-top:.5rem;border-top:1px solid #f1f5f9;">
                            <span style="font-size:.72rem;font-weight:700;color:#F97316;">🛵 <?= sanitize($riderUser['name']) ?> (Rider)</span>
                            <?php if ($hasViewer): ?>
                            <span style="font-size:.72rem;font-weight:700;color:#3b82f6;">🛒 Your Location</span>
                            <?php endif; ?>
                            <span style="font-size:.72rem;color:#64748b;margin-left:auto;">
                                <i class="fa-solid fa-circle-info me-1"></i>Updates when rider opens dashboard
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- About card -->
                <div class="pub-card">
                    <div class="pub-card-head">
                        <i class="fa-solid fa-user" style="color:#1B5E20;"></i>
                        <h6>About This Rider</h6>
                    </div>
                    <div class="pub-card-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                            <div style="background:#F8FAF8;border-radius:12px;padding:.85rem;border:1px solid #E0EDE0;">
                                <div style="font-size:.7rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Role</div>
                                <div style="font-weight:800;font-size:.9rem;">🛵 Delivery Rider</div>
                            </div>
                            <div style="background:#F8FAF8;border-radius:12px;padding:.85rem;border:1px solid #E0EDE0;">
                                <div style="font-size:.7rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Member Since</div>
                                <div style="font-weight:800;font-size:.9rem;"><?= date('M j, Y', strtotime($riderUser['created_at'])) ?></div>
                            </div>
                            <div style="background:#dcfce7;border-radius:12px;padding:.85rem;border:1px solid #86efac;">
                                <div style="font-size:.7rem;font-weight:800;color:#15803d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Completed</div>
                                <div style="font-weight:800;font-size:.9rem;color:#15803d;"><?= $delivered ?> deliveries</div>
                            </div>
                            <div style="background:#eff6ff;border-radius:12px;padding:.85rem;border:1px solid #bfdbfe;">
                                <div style="font-size:.7rem;font-weight:800;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Total Orders</div>
                                <div style="font-weight:800;font-size:.9rem;color:#1d4ed8;"><?= $totalOrders ?> orders</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>