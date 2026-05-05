<?php
ob_start();
require_once __DIR__ . '/../config/database.php';


// ── Load farmer + premium status BEFORE any HTML ─────────────────────────────
$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];
$farmerStmt = $pdo->prepare("SELECT f.*, u.profile_image, f.is_premium, f.premium_until FROM farmers f JOIN users u ON f.user_id=u.id WHERE f.user_id=?");
$farmerStmt->execute([$userId]);
$farmer = $farmerStmt->fetch();
$isPrem = !empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time();
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $category     = trim($_POST['category'] ?? '');
    $price        = floatval($_POST['price_per_kg'] ?? 0);
    $stock        = floatval($_POST['stock_kg'] ?? 0);
    $min_order    = floatval($_POST['min_order_kg'] ?? 1);
    $location     = trim($_POST['location'] ?? '');
    $harvest_date = trim($_POST['harvest_date'] ?? '');
    $is_organic   = isset($_POST['is_organic']) ? 1 : 0;
    $image        = null;

    if (!$name || !$price || !$stock || !$category) {
        $error = 'Please fill in all required fields.';
    } else {
        // Handle image upload
        if (!empty($_FILES['image']['name'])) {
            $allowed = ['jpg','jpeg','png','webp'];
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $error = 'Image must be JPG, PNG, or WebP.';
            } elseif ($_FILES['image']['size'] > 3 * 1024 * 1024) {
                $error = 'Image must be under 3MB.';
            } else {
                $uploadDir = __DIR__ . '/../assets/images/products/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $image = 'prod_' . time() . '_' . $userId . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $image);
            }
        }

if (!$error) {
    $is_early_access = $isPrem && isset($_POST['is_early_access']) ? 1 : 0;
    $stmt = $pdo->prepare("INSERT INTO products (farmer_id, name, description, category, price_per_kg, stock_kg, min_order_kg, location, harvest_date, is_organic, is_early_access, image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$userId, $name, $description, $category, $price, $stock, $min_order, $location, $harvest_date ?: null, $is_organic, $is_early_access, $image]);
    $productId = $pdo->lastInsertId();

    // Save extra carousel images
    if (!empty($_FILES['extra_images']['name'])) {
        $allowed = ['jpg','jpeg','png','webp'];
        $uploadDir = __DIR__ . '/../assets/images/products/';
        foreach ($_FILES['extra_images']['name'] as $i => $fname) {
            if (empty($fname) || $_FILES['extra_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed) || $_FILES['extra_images']['size'][$i] > 3 * 1024 * 1024) continue;
            $newName = 'prod_extra_' . time() . '_' . $userId . '_' . $i . '.' . $ext;
            move_uploaded_file($_FILES['extra_images']['tmp_name'][$i], $uploadDir . $newName);
            $pdo->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?,?,?)")
                ->execute([$productId, $newName, $i + 1]);
        }
    }

if (function_exists('setFlash')) {
        setFlash('success', "Product \"$name\" has been listed! 🌾");
    } else {
        $_SESSION['flash']['success'] = "Product \"$name\" has been listed! 🌾";
    }
    header('Location: ' . dirname($_SERVER['PHP_SELF']) . '/products.php');
    exit();
}
    }
}
// ── Now safe to output HTML ───────────────────────────────────────────────────
$page_title = 'Add Product';
require_once __DIR__ . '/../includes/header.php';
requireRole('farmer');
?>
<div style="background:<?= $isPrem ? 'linear-gradient(180deg,#fffbeb 0%,#fef9ee 40%,var(--bg) 100%)' : 'var(--bg)' ?>;min-height:100vh;padding-bottom:3rem;">
    <div class="page-header" style="<?= $isPrem ? 'background:linear-gradient(135deg,#78350f,#92400e,#b45309,#d97706);box-shadow:0 4px 20px rgba(217,119,6,.25);' : '' ?>">
        <div class="container">
            <div class="d-flex align-items-center gap-2">
                <a href="dashboard.php" style="color:<?= $isPrem ? 'white' : 'var(--primary)' ?>;text-decoration:none;"><i class="fa-solid fa-arrow-left"></i></a>
                <div>
                    <h1 style="<?= $isPrem ? 'color:white;' : '' ?>">
                        <?php if ($isPrem): ?>
                            ⭐ Add New Product
                        <?php else: ?>
                            <i class="fa-solid fa-plus-circle text-green me-2"></i>Add New Product
                        <?php endif; ?>
                    </h1>
                    <div class="page-breadcrumb" style="<?= $isPrem ? 'color:rgba(255,255,255,.8);' : '' ?>">
                        <a href="dashboard.php" style="<?= $isPrem ? 'color:rgba(255,255,255,.7);' : '' ?>">Dashboard</a> › Add Product
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">
            <div class="col-lg-3">
                <div class="gl-sidebar">
                    <div class="gl-sidebar-header">
    <?php if (!empty($farmer['profile_image'])): ?>
        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($farmer['profile_image']) ?>"
             style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid var(--primary);margin-bottom:0.5rem;">
    <?php else: ?>
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'],0,1)) ?></div>
    <?php endif; ?>
    <div class="user-name"><?= sanitize($_SESSION['user_name']) ?></div>
<div class="user-role">🌾 Farmer</div>
<?php if (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()): ?>
<div style="margin-top:8px;display:flex;flex-direction:column;align-items:center;gap:3px;">
    <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;letter-spacing:.04em;box-shadow:0 2px 8px rgba(217,119,6,.35);">⭐ PREMIUM</span>
    <span style="font-size:.65rem;color:#1a1a1a;font-weight:700;">Until <?= date('M j, Y', strtotime($farmer['premium_until'])) ?></span>
</div>
<?php else: ?>
<div style="margin-top:8px;">
    <a href="premium.php" style="display:inline-block;background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;text-decoration:none;letter-spacing:.04em;">
        ⭐ Go Premium
    </a>
</div>
<?php endif; ?>
</div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="products.php"><i class="fa-solid fa-seedling"></i> My Products</a>
                        <a href="add_product.php" class="active"><i class="fa-solid fa-plus-circle"></i> Add Product</a>
                        <div class="nav-divider"></div>
                        <a href="../orders/index.php"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a href="../messages/index.php"><i class="fa-solid fa-comments"></i> Messages</a>
                        <a href="../market/prices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                    </nav>
                </div>
            </div>

            <div class="col-lg-9">
                <?php if ($error): ?>
                <div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:0.8rem 1rem;margin-bottom:1.2rem;font-size:0.88rem;font-weight:600;">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="gl-card">
                                <div class="gl-card-body">
                                    <h5 style="font-weight:800;margin-bottom:1.5rem;">Product Information</h5>

                                    <div class="gl-form-group">
                                        <label>Product Name <span style="color:red;">*</span></label>
                                        <div class="gl-input-wrap">
                                            <i class="fa-solid fa-seedling input-icon"></i>
                                            <input type="text" name="name" class="gl-input" placeholder="e.g. Organic Pechay" value="<?= sanitize($_POST['name'] ?? '') ?>" required>
                                        </div>
                                    </div>

                                    <div class="gl-form-group">
                                        <label>Category <span style="color:red;">*</span></label>
                                        <div class="gl-input-wrap">
                                            <i class="fa-solid fa-tags input-icon"></i>
                                            <select name="category" class="gl-select" required>
                                                <option value="">Select category...</option>
                                              <?php foreach (['Vegetables','Fruits','Grains','Crops','Coffee','Livestock','Seafood','Others'] as $cat): ?>
                                                <option value="<?= $cat ?>" <?= ($_POST['category'] ?? '') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="gl-form-group">
                                        <label>Description</label>
                                        <div class="gl-input-wrap">
                                            <i class="fa-solid fa-pen input-icon" style="top:14px;transform:none;"></i>
                                            <textarea name="description" class="gl-input" placeholder="Describe your product — freshness, growing method, taste..." rows="3"><?= sanitize($_POST['description'] ?? '') ?></textarea>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-sm-4">
                                            <div class="gl-form-group">
                                                <label>Price per kg (₱) <span style="color:red;">*</span></label>
                                                <div class="gl-input-wrap">
                                                    <i class="fa-solid fa-peso-sign input-icon"></i>
                                                    <input type="number" name="price_per_kg" class="gl-input" placeholder="0.00" step="0.01" min="0" value="<?= $_POST['price_per_kg'] ?? '' ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-4">
                                            <div class="gl-form-group">
                                                <label>Stock (kg) <span style="color:red;">*</span></label>
                                                <div class="gl-input-wrap">
                                                    <i class="fa-solid fa-weight-scale input-icon"></i>
                                                    <input type="number" name="stock_kg" class="gl-input" placeholder="0.00" step="0.01" min="0" value="<?= $_POST['stock_kg'] ?? '' ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-4">
                                            <div class="gl-form-group">
                                                <label>Min. Order (kg)</label>
                                                <div class="gl-input-wrap">
                                                    <i class="fa-solid fa-scale-balanced input-icon"></i>
                                                    <input type="number" name="min_order_kg" class="gl-input" placeholder="1.00" step="0.5" min="0.5" value="<?= $_POST['min_order_kg'] ?? '1' ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-sm-6">
                                            <div class="gl-form-group">
                                                <label>Location</label>
                                                <div class="gl-input-wrap">
                                                    <i class="fa-solid fa-location-dot input-icon"></i>
                                                    <input type="text" name="location" class="gl-input" placeholder="e.g. Davao del Sur" value="<?= sanitize($_POST['location'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="gl-form-group">
                                                <label>Harvest Date</label>
                                                <div class="gl-input-wrap">
                                                    <i class="fa-solid fa-calendar input-icon"></i>
                                                    <input type="date" name="harvest_date" class="gl-input" value="<?= $_POST['harvest_date'] ?? '' ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div style="background:var(--pale-green);border-radius:12px;padding:1rem;display:flex;align-items:center;gap:10px;">
                                        <input type="checkbox" name="is_organic" id="is_organic" value="1" <?= ($_POST['is_organic'] ?? '') ? 'checked' : '' ?> style="width:20px;height:20px;accent-color:var(--primary);">
                                        <label for="is_organic" style="cursor:pointer;font-weight:700;color:var(--primary-dark);margin:0;">
                                            🌿 This product is organically grown (no synthetic pesticides/fertilizers)
                                        </label>
                                    </div>
                                    <?php if ($isPrem): ?>
                                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:1rem;display:flex;align-items:center;gap:10px;margin-top:.75rem;">
                                        <input type="checkbox" name="is_early_access" id="is_early_access" value="1"
                                               <?= ($_POST['is_early_access'] ?? '') ? 'checked' : '' ?>
                                               style="width:20px;height:20px;accent-color:#d97706;">
                                        <label for="is_early_access" style="cursor:pointer;font-weight:700;color:#92400e;margin:0;">
                                            🌾 Early Harvest Access — visible only to Premium Buyers before public listing
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                       <div class="col-lg-4">
                            <div class="gl-card">
                                <div class="gl-card-body">
                                    <h5 style="font-weight:800;margin-bottom:0.3rem;">Product Images</h5>
                                    <p style="font-size:0.75rem;color:var(--text-muted);margin-bottom:1rem;">First image is the main display photo. Others appear in the carousel.</p>

                                    <!-- Main Image -->
                                    <div style="font-size:0.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">
                                        🖼️ Main Photo <span style="color:red;">*</span>
                                    </div>
                                    <div class="img-upload-area" id="imageUploadArea0" onclick="document.getElementById('imageInput0').click()" style="margin-bottom:1rem;">
                                        <div id="uploadPlaceholder0">
                                            <div style="font-size:2rem;margin-bottom:0.4rem;">📷</div>
                                            <div style="font-weight:700;color:var(--text);font-size:0.82rem;">Click to upload main photo</div>
                                            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:3px;">JPG, PNG, WebP · Max 3MB</div>
                                        </div>
                                    </div>
                                    <input type="file" name="image" id="imageInput0" accept="image/*" style="display:none;" onchange="previewImg(this, 'imageUploadArea0', 'uploadPlaceholder0')">

                                    <!-- Extra Images -->
                                    <div style="font-size:0.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">
                                        🎠 Carousel Photos (up to 3)
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:8px;">
                                        <?php foreach ([1,2,3] as $n): ?>
                                        <div class="img-upload-area" id="imageUploadArea<?= $n ?>" onclick="document.getElementById('imageInput<?= $n ?>').click()"
                                             style="height:80px;flex-direction:row;gap:10px;padding:0.6rem 1rem;">
                                            <div id="uploadPlaceholder<?= $n ?>" style="display:flex;align-items:center;gap:10px;width:100%;">
                                                <div style="font-size:1.4rem;">📸</div>
                                                <div>
                                                    <div style="font-weight:700;color:var(--text);font-size:0.78rem;">Photo <?= $n ?></div>
                                                    <div style="font-size:0.68rem;color:var(--text-muted);">Optional · Max 3MB</div>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="file" name="extra_images[]" id="imageInput<?= $n ?>" accept="image/*" style="display:none;"
                                               onchange="previewImg(this, 'imageUploadArea<?= $n ?>', 'uploadPlaceholder<?= $n ?>')">
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="gl-card mt-3">
                                <div class="gl-card-body">
                                    <h5 style="font-weight:800;margin-bottom:1rem;">💡 Tips</h5>
                                    <ul style="list-style:none;padding:0;margin:0;font-size:0.83rem;color:var(--text-muted);">
                                        <li style="margin-bottom:0.5rem;">✅ Use clear, bright photos</li>
                                        <li style="margin-bottom:0.5rem;">✅ Set fair and competitive prices</li>
                                        <li style="margin-bottom:0.5rem;">✅ Update stock daily</li>
                                        <li style="margin-bottom:0.5rem;">✅ Add accurate harvest dates</li>
                                        <li>✅ Mark organic products for higher trust</li>
                                    </ul>
                                </div>
                            </div>

                            <div class="mt-3 d-grid gap-2">
                                <button type="submit" class="btn-green justify-content-center" style="padding:0.9rem;">
                                    <i class="fa-solid fa-seedling"></i> List Product
                                </button>
                                <a href="products.php" class="btn-outline-green justify-content-center" style="padding:0.9rem;">
                                    Cancel
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function previewImg(input, areaId, placeholderId) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    const area   = document.getElementById(areaId);
    const ph     = document.getElementById(placeholderId);
    reader.onload = e => {
        ph.style.display = 'none';
        let img = area.querySelector('img.preview-thumb');
        if (!img) {
            img = document.createElement('img');
            img.className = 'preview-thumb';
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:10px;position:absolute;inset:0;';
            area.style.position = 'relative';
            area.appendChild(img);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
