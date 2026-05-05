# 🌿 GreenLink Innovators — Digital Agritech Marketplace

**A full-stack PHP & MySQL web application connecting Mindanao farmers and restaurant buyers.**

---

## 📋 Tech Stack
- **Backend:** PHP 7.4+ (OOP + PDO)
- **Database:** MySQL 5.7+
- **Frontend:** HTML5, CSS3, Bootstrap 5, JavaScript
- **Icons:** Font Awesome 6
- **Fonts:** Google Fonts (Nunito + Playfair Display)

---

## 🚀 Quick Setup

### 1. Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx with `mod_rewrite`
- XAMPP, WAMP, or Laragon (recommended for local dev)

### 2. Install Steps

1. **Copy files to web root:**
   ```
   /htdocs/greenlink/        (XAMPP)
   /www/greenlink/           (WAMP/Laragon)
   ```

2. **Create the database:**
   - Open phpMyAdmin
   - Run the file: `config/schema.sql`
   - This creates all tables and seeds demo data

3. **Configure database connection:**
   Edit `/config/database.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');           // your MySQL password
   define('DB_NAME', 'greenlink_db');
   define('BASE_URL', 'http://localhost/greenlink');
   ```

4. **Create image upload directory:**
   ```
   /assets/images/products/      (auto-created on first upload)
   ```

5. **Open in browser:**
   ```
   http://localhost/greenlink/
   ```

---

## 🔑 Demo Accounts

All demo passwords are: **password**

| Role   | Email                       |
|--------|-----------------------------|
| Farmer | juan@greenlink.ph           |
| Farmer | maria@greenlink.ph          |
| Farmer | pedro@greenlink.ph          |
| Buyer  | restaurant@greenlink.ph     |
| Buyer  | freshmart@greenlink.ph      |
| Admin  | admin@greenlink.ph          |

---

## 📁 File Structure

```
greenlink/
├── config/
│   ├── database.php          # DB connection & helpers
│   └── schema.sql            # Full DB schema + sample data
├── auth/
│   ├── login.php             # Login page
│   ├── register.php          # Registration (farmer/buyer)
│   └── logout.php            # Logout handler
├── farmer/
│   ├── dashboard.php         # Farmer dashboard
│   ├── products.php          # Product list management
│   ├── add_product.php       # Add new product
│   ├── edit_product.php      # Edit existing product
│   ├── delete_product.php    # Delete product handler
│   └── profile.php           # Edit profile (shared)
├── buyer/
│   ├── dashboard.php         # Buyer dashboard
│   ├── browse.php            # Product marketplace
│   ├── product.php           # Product detail + order
│   └── profile.php           # Profile redirect
├── orders/
│   ├── index.php             # Orders list
│   ├── detail.php            # Order detail + tracker
│   └── update_status.php     # Status update handler
├── messages/
│   └── index.php             # Real-time-style messaging
├── market/
│   └── prices.php            # Market price dashboard
├── admin/
│   └── dashboard.php         # Admin overview
├── includes/
│   ├── header.php            # Navbar + HTML head
│   └── footer.php            # Footer + scripts
├── assets/
│   ├── css/
│   │   └── main.css          # Full custom stylesheet
│   ├── js/
│   │   └── main.js           # Custom JavaScript
│   └── images/
│       └── products/         # Uploaded product images
└── index.php                 # Homepage
```

---

## 🧩 Core Features

### 🔐 Authentication
- Register as Farmer or Buyer
- Role-based dashboards
- Password hashing (bcrypt)
- Session management

### 🌾 Farmer Features
- Dashboard with stats (products, orders, earnings)
- Product CRUD (create, read, update, delete)
- Image upload with preview
- Toggle product availability
- Order management with status updates

### 🛒 Buyer Features
- Browse marketplace with filters (category, price, location, organic)
- Search bar with live filtering
- Product detail page with order form
- Auto-calculated order total
- Order history tracking

### 📦 Orders
- Full order lifecycle: Pending → Confirmed → Processing → Shipped → Completed
- Visual progress tracker
- Cancel orders
- Both parties can view order details

### 💬 Messaging
- Chat-style messaging interface
- Conversations between farmers and buyers
- Unread message counters

### 📊 Market Prices
- Daily price data for Mindanao produce
- Market price vs GreenLink suggested price
- Grouped by category

---

## 🔐 Security Features
- PDO prepared statements (SQL injection prevention)
- `htmlspecialchars()` output escaping (XSS prevention)
- `password_hash()` / `password_verify()` for passwords
- Session-based authentication
- Role-based access control
- File upload validation (type + size)

---

## 🎨 Design System

| Token          | Value              |
|----------------|--------------------|
| Primary Green  | `#2E7D32`          |
| Light Green    | `#A5D6A7`          |
| Earth Brown    | `#6D4C41`          |
| Background     | `#F1F8E9`          |
| Card Radius    | `20px`             |
| Font Body      | Nunito             |
| Font Heading   | Playfair Display   |

---

## 📱 Mobile Responsive
- Mobile-first Bootstrap 5 grid
- Large readable text for farmers
- Sticky navbar
- Touch-friendly buttons

---

## 🌱 Future Enhancements
- SMS notifications (Semaphore/Twilio)
- Payment gateway (GCash/Maya)
- Real-time chat (WebSockets)
- Push notifications
- QR code for product verification
- Analytics dashboard
- Multi-image product uploads
- Review & rating system

---

*Made with 💚 for Mindanao Agriculture — GreenLink Innovators*
