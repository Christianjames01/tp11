<?php
ob_start();
$page_title = 'Edit Product';
require_once __DIR__ . '/../includes/header.php';
requireRole('farmer');

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];
$pid = intval($_GET['id'] ?? 0);
$farmer = $pdo->prepare("SELECT f.*, u.profile_image FROM farmers f JOIN users u ON f.user_id=u.id WHERE f.user_id=?");
$farmer->execute([$userId]);
$farmer = $farmer->fetch();

$stmt = $pdo->prepare("SELECT * FROM products WHERE id=? AND farmer_id=?");
$stmt->execute([$pid, $userId]); $product = $stmt->fetch();
if (!$product) { header('Location: products.php'); exit(); }

// Check premium status early so POST handler can use it
$farmerPrem = $pdo->prepare("SELECT is_premium, premium_until FROM farmers WHERE user_id=?");
$farmerPrem->execute([$userId]);
$farmerPremRow = $farmerPrem->fetch();
$editIsPrem = !empty($farmerPremRow['is_premium']) && !empty($farmerPremRow['premium_until']) && strtotime($farmerPremRow['premium_until']) > time();

$error = '';
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
    $is_available = isset($_POST['is_available']) ? 1 : 0;
    $image        = $product['image'];

    if (!$name || !$price || !$stock || !$category) {
        $error = 'Please fill in all required fields.';
    } else {
        if (!empty($_FILES['image']['name'])) {
            $allowed = ['jpg','jpeg','png','webp'];
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed) && $_FILES['image']['size'] <= 3*1024*1024) {
                $uploadDir = __DIR__ . '/../assets/images/products/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $image = 'prod_' . time() . '_' . $userId . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $image);
            }
        }
       $is_early_access = isset($editIsPrem) && $editIsPrem && isset($_POST['is_early_access']) ? 1 : 0;
        $pdo->prepare("UPDATE products SET name=?,description=?,category=?,price_per_kg=?,stock_kg=?,min_order_kg=?,location=?,harvest_date=?,is_organic=?,is_available=?,is_early_access=?,image=? WHERE id=? AND farmer_id=?")
    ->execute([$name,$description,$category,$price,$stock,$min_order,$location,$harvest_date ?: null,$is_organic,$is_available,$is_early_access,$image,$pid,$userId]);

// Save new extra carousel images
if (!empty($_FILES['extra_images']['name'])) {
    $allowed   = ['jpg','jpeg','png','webp'];
    $uploadDir = __DIR__ . '/../assets/images/products/';
    // Get current count to assign correct sort_order
    $currentCount = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id=?");
    $currentCount->execute([$pid]); $currentCount = $currentCount->fetchColumn();
    foreach ($_FILES['extra_images']['name'] as $i => $fname) {
        if (empty($fname) || $_FILES['extra_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed) || $_FILES['extra_images']['size'][$i] > 3*1024*1024) continue;
        $newName = 'prod_extra_' . time() . '_' . $userId . '_' . $i . '.' . $ext;
        move_uploaded_file($_FILES['extra_images']['tmp_name'][$i], $uploadDir . $newName);
        // Replace existing slot if it exists, otherwise insert
        $existingId = intval($_POST['existing_extra_ids'][$i] ?? 0);
        if ($existingId) {
            $pdo->prepare("UPDATE product_images SET image=? WHERE id=? AND product_id=?")
                ->execute([$newName, $existingId, $pid]);
        } else {
            $pdo->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?,?,?)")
                ->execute([$pid, $newName, $i + 1]);
        }
    }
}

setFlash('success', "Product updated successfully! 🌾");
header('Location: products.php'); exit();
    }
}
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
    <div class="page-header">
        <div class="container">
            <h1><i class="fa-solid fa-pen-to-square text-green me-2"></i>Edit Product</h1>
            <div class="page-breadcrumb"><a href="dashboard.php">Dashboard</a> › <a href="products.php">Products</a> › Edit</div>
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
</div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="products.php" class="active"><i class="fa-solid fa-seedling"></i> My Products</a>
                        <a href="add_product.php"><i class="fa-solid fa-plus-circle"></i> Add Product</a>
                        <div class="nav-divider"></div>
                        <a href="../orders/index.php"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a href="../messages/index.php"><i class="fa-solid fa-comments"></i> Messages</a>
                    </nav>
                </div>
            </div>

            <div class="col-lg-9">
                <?php if ($error): ?>
                <div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:0.8rem 1rem;margin-bottom:1rem;font-weight:600;font-size:0.88rem;">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="gl-card">
                                <div class="gl-card-body">
                                    <h5 style="font-weight:800;margin-bottom:1.5rem;">Product Information</h5>
                                    <div class="gl-form-group">
                                        <label>Product Name *</label>
                                        <div class="gl-input-wrap"><i class="fa-solid fa-seedling input-icon"></i>
                                        <input type="text" name="name" class="gl-input" value="<?= sanitize($product['name']) ?>" required></div>
                                    </div>
                                    <div class="gl-form-group">
                                        <label>Category *</label>
                                        <div class="gl-input-wrap"><i class="fa-solid fa-tags input-icon"></i>
                                        <select name="category" class="gl-select" required>
                                            <?php foreach (['Vegetables','Fruits','Grains','Coffee','Livestock','Seafood','Others'] as $cat): ?>
                                            <option value="<?= $cat ?>" <?= $product['category']===$cat?'selected':'' ?>><?= $cat ?></option>
                                            <?php endforeach; ?>
                                        </select></div>
                                    </div>
                                    <div class="gl-form-group">
                                        <label>Description</label>
                                        <div class="gl-input-wrap"><i class="fa-solid fa-pen input-icon" style="top:14px;transform:none;"></i>
                                        <textarea name="description" class="gl-input" rows="3"><?= sanitize($product['description']) ?></textarea></div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-4">
                                            <div class="gl-form-group">
                                                <label>Price/kg (₱) *</label>
                                                <div class="gl-input-wrap"><i class="fa-solid fa-peso-sign input-icon"></i>
                                                <input type="number" name="price_per_kg" class="gl-input" step="0.01" min="0" value="<?= $product['price_per_kg'] ?>" required></div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="gl-form-group">
                                                <label>Stock (kg) *</label>
                                                <div class="gl-input-wrap"><i class="fa-solid fa-weight-scale input-icon"></i>
                                                <input type="number" name="stock_kg" class="gl-input" step="0.01" min="0" value="<?= $product['stock_kg'] ?>" required></div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="gl-form-group">
                                                <label>Min Order (kg)</label>
                                                <div class="gl-input-wrap"><i class="fa-solid fa-scale-balanced input-icon"></i>
                                                <input type="number" name="min_order_kg" class="gl-input" step="0.5" min="0.5" value="<?= $product['min_order_kg'] ?>"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="gl-form-group">
                                                <label>Location</label>
                                                <div class="gl-input-wrap"><i class="fa-solid fa-location-dot input-icon"></i>
                                                <input type="text" name="location" class="gl-input" value="<?= sanitize($product['location']) ?>"></div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="gl-form-group">
                                                <label>Harvest Date</label>
                                                <div class="gl-input-wrap"><i class="fa-solid fa-calendar input-icon"></i>
                                                <input type="date" name="harvest_date" class="gl-input" value="<?= $product['harvest_date'] ?>"></div>
                                            </div>
                                        </div>
                                    </div>
                                  <div class="row g-2">
                                        <div class="col-6">
                                            <div style="background:var(--pale-green);border-radius:12px;padding:0.8rem;display:flex;align-items:center;gap:8px;">
                                                <input type="checkbox" name="is_organic" id="is_organic" <?= $product['is_organic']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--primary);">
                                                <label for="is_organic" style="cursor:pointer;font-weight:700;color:var(--primary-dark);margin:0;font-size:0.85rem;">🌿 Organic</label>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div style="background:var(--bg);border-radius:12px;padding:0.8rem;display:flex;align-items:center;gap:8px;border:1px solid var(--border);">
                                                <input type="checkbox" name="is_available" id="is_available" <?= $product['is_available']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--primary);">
                                                <label for="is_available" style="cursor:pointer;font-weight:700;color:var(--text);margin:0;font-size:0.85rem;">✅ Available</label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                  if ($editIsPrem): ?>
                                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:0.8rem;display:flex;align-items:center;gap:8px;margin-top:.5rem;">
                                        <input type="checkbox" name="is_early_access" id="is_early_access"
                                               <?= !empty($product['is_early_access']) ? 'checked' : '' ?>
                                               style="width:18px;height:18px;accent-color:#d97706;">
                                        <label for="is_early_access" style="cursor:pointer;font-weight:700;color:#92400e;margin:0;font-size:0.85rem;">
                                            🌾 Early Harvest Access
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
                🖼️ Main Photo
            </div>
            <div class="img-upload-area" id="imageUploadArea0" onclick="document.getElementById('imageInput0').click()" style="margin-bottom:1rem;position:relative;">
                <?php if ($product['image']): ?>
                    <img src="../assets/images/products/<?= sanitize($product['image']) ?>" class="preview-thumb" style="width:100%;height:100%;object-fit:cover;border-radius:10px;position:absolute;inset:0;">
                    <div id="uploadPlaceholder0" style="display:none;"></div>
                <?php else: ?>
                    <div id="uploadPlaceholder0">
                        <div style="font-size:2rem;margin-bottom:0.4rem;">📷</div>
                        <div style="font-weight:700;color:var(--text);font-size:0.82rem;">Click to upload main photo</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:3px;">JPG, PNG, WebP · Max 3MB</div>
                    </div>
                <?php endif; ?>
            </div>
           <input type="file" name="image" id="imageInput0" accept="image/*" style="display:none;" onchange="previewImg(this, 'imageUploadArea0', 'uploadPlaceholder0')">
            <?php if ($product['image']): ?>
            <a href="delete_product_image.php?main=1&product_id=<?= $pid ?>"
               onclick="return confirm('Delete main photo?')"
               style="display:inline-flex;align-items:center;gap:5px;color:#ef4444;font-size:0.72rem;font-weight:700;text-decoration:none;margin-top:4px;">
                <i class="fa-solid fa-trash"></i> Remove main photo
            </a>
            <?php endif; ?>

           <!-- Extra Carousel Images -->
            <div style="font-size:0.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">
                🎠 Carousel Photos (up to 3)
            </div>
            <?php
            $existingExtras = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order ASC LIMIT 3");
            $existingExtras->execute([$pid]);
            $existingExtras = $existingExtras->fetchAll();
            ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ([1,2,3] as $n):
                    $existing = $existingExtras[$n-1] ?? null;
                ?>
                <div style="position:relative;">
                    <div class="img-upload-area" id="imageUploadArea<?= $n ?>" onclick="document.getElementById('imageInput<?= $n ?>').click()"
                         style="height:80px;flex-direction:row;gap:10px;padding:0.6rem 1rem;position:relative;">
                        <?php if ($existing): ?>
                            <img src="../assets/images/products/<?= sanitize($existing['image']) ?>" class="preview-thumb"
                                 style="width:100%;height:100%;object-fit:cover;border-radius:10px;position:absolute;inset:0;">
                            <div id="uploadPlaceholder<?= $n ?>" style="display:none;"></div>
                            <input type="hidden" name="existing_extra_ids[]" value="<?= $existing['id'] ?>">
                        <?php else: ?>
                            <div id="uploadPlaceholder<?= $n ?>" style="display:flex;align-items:center;gap:10px;width:100%;">
                                <div style="font-size:1.4rem;">📸</div>
                                <div>
                                    <div style="font-weight:700;color:var(--text);font-size:0.78rem;">Photo <?= $n ?></div>
                                    <div style="font-size:0.68rem;color:var(--text-muted);">Optional · Max 3MB</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($existing): ?>
                    <a href="delete_product_image.php?id=<?= $existing['id'] ?>&product_id=<?= $pid ?>"
                       onclick="return confirm('Delete this photo?')"
                       style="position:absolute;top:4px;right:4px;background:#ef4444;color:white;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:0.65rem;text-decoration:none;z-index:10;font-weight:800;line-height:1;">✕</a>
                    <?php endif; ?>
                </div>
                <input type="file" name="extra_images[]" id="imageInput<?= $n ?>" accept="image/*" style="display:none;"
                       onchange="previewImg(this, 'imageUploadArea<?= $n ?>', 'uploadPlaceholder<?= $n ?>')">
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="mt-3 d-grid gap-2">
        <button type="submit" class="btn-green justify-content-center" style="padding:0.9rem;">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
        <a href="products.php" class="btn-outline-green justify-content-center" style="padding:0.9rem;">Cancel</a>
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
        if (ph) ph.style.display = 'none';
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
