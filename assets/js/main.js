// GreenLink Innovators - Main JavaScript

// ── TOAST NOTIFICATIONS ──
const Toast = {
    container: null,
    init() {
        this.container = document.getElementById('toast-container');
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        }
    },
    show(message, type = 'success', duration = 4000) {
        this.init();
        const icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
        const toast = document.createElement('div');
        toast.className = `gl-toast ${type}`;
        toast.innerHTML = `<span>${icons[type] || '🌿'}</span><span>${message}</span>`;
        toast.addEventListener('click', () => toast.remove());
        this.container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
};

// Auto-show flash messages from PHP
document.addEventListener('DOMContentLoaded', function () {
    const flash = document.getElementById('php-flash');
    if (flash) {
        const type = flash.dataset.type;
        const message = flash.dataset.message;
        if (message) Toast.show(message, type || 'success');
    }

    // Initialize image preview
    initImagePreview();

    // Initialize smooth animations
    initAnimations();

    // Initialize search
    initSearch();

    // Navbar active state
    setNavActive();
});

// ── IMAGE PREVIEW ON UPLOAD ──
function initImagePreview() {
    const fileInputs = document.querySelectorAll('input[type="file"][data-preview]');
    fileInputs.forEach(input => {
        const previewId = input.dataset.preview;
        const preview = document.getElementById(previewId);
        if (!preview) return;

        input.addEventListener('change', function () {
            const file = this.files[0];
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = e => {
                    preview.classList.add('has-preview');
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview">
                        <div style="padding:8px;text-align:center;font-size:0.78rem;color:#636E72;">${file.name}</div>`;
                };
                reader.readAsDataURL(file);
            }
        });
    });
}

// ── SCROLL ANIMATIONS ──
function initAnimations() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.animate-on-scroll').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(el);
    });
}

// ── PRODUCT SEARCH FILTER ──
function initSearch() {
    const searchInput = document.getElementById('product-search');
    const productCards = document.querySelectorAll('.product-card-wrap');
    if (!searchInput || !productCards.length) return;

    searchInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        productCards.forEach(card => {
            const name = (card.querySelector('.product-card-title')?.textContent || '').toLowerCase();
            const cat = (card.querySelector('.badge-category')?.textContent || '').toLowerCase();
            const loc = (card.querySelector('.prod-location')?.textContent || '').toLowerCase();
            const match = !query || name.includes(query) || cat.includes(query) || loc.includes(query);
            card.style.display = match ? '' : 'none';
        });

        const visible = [...productCards].filter(c => c.style.display !== 'none').length;
        const noResults = document.getElementById('no-results');
        if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
    });
}

// ── NAVBAR ACTIVE STATE ──
function setNavActive() {
    const links = document.querySelectorAll('.gl-navbar .nav-link');
    const path = window.location.pathname;
    links.forEach(link => {
        const href = link.getAttribute('href') || '';
        if (path.includes(href) && href !== '/') {
            link.classList.add('active');
        }
    });
}

// ── PRICE FILTER ──
function filterByPrice(min, max) {
    document.querySelectorAll('.product-card-wrap').forEach(card => {
        const priceEl = card.querySelector('.product-card-price');
        if (!priceEl) return;
        const price = parseFloat(priceEl.dataset.price || 0);
        const show = (!min || price >= min) && (!max || price <= max);
        card.style.display = show ? '' : 'none';
    });
}

// ── CATEGORY FILTER ──
function filterByCategory(category) {
    document.querySelectorAll('.product-card-wrap').forEach(card => {
        const cat = card.dataset.category || '';
        card.style.display = (!category || cat === category) ? '' : 'none';
    });
}

// ── CONFIRM DELETE ──
function confirmDelete(url, message) {
    if (confirm(message || 'Are you sure? This action cannot be undone.')) {
        window.location.href = url;
    }
}

// ── FORMAT CURRENCY ──
function formatPHP(amount) {
    return '₱' + parseFloat(amount).toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

// ── AUTO-RESIZE TEXTAREA ──
document.querySelectorAll('textarea.auto-resize').forEach(el => {
    el.style.minHeight = '60px';
    el.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });
});

// ── SMOOTH SCROLL ──
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});
