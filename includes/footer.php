<footer class="gl-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:40px;height:40px;background:rgba(255,255,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;">🌿</div>
                    <h4 style="color:white;font-family:'Playfair Display',serif;margin:0;font-size:1.3rem;">GreenLink</h4>
                </div>
                <p style="font-size:0.88rem;line-height:1.7;opacity:0.75;">Empowering Mindanao farmers and connecting them directly to restaurant buyers through digital innovation and sustainable agriculture.</p>
                <div class="d-flex gap-2 mt-3">
                    <a href="#" style="width:36px;height:36px;background:rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-size:0.9rem;text-decoration:none;"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" style="width:36px;height:36px;background:rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-size:0.9rem;text-decoration:none;"><i class="fa-brands fa-instagram"></i></a>
                    <a href="#" style="width:36px;height:36px;background:rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-size:0.9rem;text-decoration:none;"><i class="fa-brands fa-twitter"></i></a>
                </div>
            </div>
            <div class="col-lg-2 col-6">
                <h5>Marketplace</h5>
                <a href="<?= BASE_URL ?>/buyer/browse.php">Browse Products</a>
                <a href="<?= BASE_URL ?>/market/prices.php">Market Prices</a>
                <a href="<?= BASE_URL ?>/auth/register.php">Sell on GreenLink</a>
                <a href="#">How It Works</a>
            </div>
            <div class="col-lg-2 col-6">
                <h5>Account</h5>
                <a href="<?= BASE_URL ?>/auth/login.php">Login</a>
                <a href="<?= BASE_URL ?>/auth/register.php">Register</a>
                <a href="<?= BASE_URL ?>/orders/index.php">My Orders</a>
                <a href="<?= BASE_URL ?>/messages/index.php">Messages</a>
            </div>
            <div class="col-lg-4">
                <h5>Contact Us</h5>
                <p style="font-size:0.85rem;opacity:0.75;"><i class="fa-solid fa-location-dot me-2"></i>Davao City, Mindanao, Philippines</p>
                <p style="font-size:0.85rem;opacity:0.75;"><i class="fa-solid fa-envelope me-2"></i>hello@greenlink.ph</p>
                <p style="font-size:0.85rem;opacity:0.75;"><i class="fa-solid fa-phone me-2"></i>(082) 555-FARM</p>
                <div class="mt-3" style="background:rgba(255,255,255,0.08);border-radius:10px;padding:1rem;">
                    <p style="font-size:0.8rem;margin:0;opacity:0.75;">🌾 Proudly supporting Mindanao farmers</p>
                </div>
            </div>
        </div>
        <div class="gl-footer-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>&copy; <?= date('Y') ?> GreenLink Innovators. All rights reserved.</span>
            <span>Made with 💚 for Mindanao Agriculture</span>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<?php if (isset($extra_js)) echo $extra_js; ?>

<style>
@keyframes cartModalIn {
    from { opacity:0; transform:scale(.92) translateY(16px); }
    to   { opacity:1; transform:scale(1)   translateY(0); }
}
</style>


<!-- Out of Stock Modal -->
<?php if (isLoggedIn() && $_SESSION['role'] === 'buyer'): ?>
<div id="outOfStockModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:white;border-radius:20px;max-width:380px;width:100%;padding:2rem;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.25);">
        <div style="font-size:3rem;margin-bottom:1rem;">🚫</div>
        <h5 style="font-weight:800;margin-bottom:.5rem;" id="modalProductName"></h5>
        <p style="font-size:.85rem;color:#6B7280;">by <strong id="modalFarmerName"></strong> is currently out of stock.</p>
        <p style="font-size:.82rem;color:#9CA3AF;margin-bottom:1.5rem;">Check back soon — farmers restock regularly!</p>
        <button onclick="closeOutOfStockModal()" class="btn-green" style="padding:.65rem 2rem;">Got it</button>
    </div>
</div>
<?php endif; ?>


</body>
</html>
<?php ob_end_flush(); ?>