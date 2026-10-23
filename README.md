# RentConnect: Apartment Management, Maintenance Ticketing, and Payment Tracker

RentConnect is an intuitive, claymorphic property management and business intelligence platform built with **PHP, HTML, CSS, Vanilla JavaScript, and SQL (MySQL)**.

---

## 🏛️ System Architecture & Business Logic

1. **Property Architecture**:
   - Manages **exactly 1 apartment property consisting of exactly 3 units**:
     - **Unit 101**: ₱15,000.00/mo
     - **Unit 102**: ₱18,000.00/mo
     - **Unit 103**: ₱22,000.00/mo
   - **Clean UI & Property Logic**: Completely free from marketing placeholder names. Identified strictly by unit number (`Unit 101`, `Unit 102`, `Unit 103`).
   - **Unlimited Tenant Capacity**: No limit to the number of tenants per unit.
2. **Account Creation & Security Policy**:
   - **No Public Registration**: Only the landlord has the authority to create, assign, or delete tenant accounts.
   - **Single Centralized Login (`login.php`)**: Clean, focused interface with the main heading strictly titled **"Log in"** (no demo filler, placeholder accounts, or quick-fill buttons).
3. **Strict Database Seeding**:
   - 👑 **Landlord (Admin)**: Username: `admin` | Password: `admin123` | Name: **Lead Dimaculangan** (Title: "Landlord")
   - 🔑 **Active Tenants (Strictly 2)**:
     - **Tenant 1**: Username: `tenant1` | Password: `111111` (Juan Dela Cruz, Unit 101)
     - **Tenant 2**: Username: `tenant2` | Password: `222222` (Maria Santos, Unit 102)
   - Unit 103 currently has 0 tenants. All arbitrary mock data and placeholder accounts have been strictly eliminated.
4. **Currency Display**:
   - Strictly utilizes the standard **`₱`** symbol across all figures, charts, and metrics (no text displays of words).
5. **Manual Payment Verification Workflow**:
   - Tenants submit payment receipts via drop-zone (`status = 'pending'`).
   - Landlord inspects proof files via the dedicated **Payment Verification & Audit** section.
   - Landlord manually approves or rejects receipts with custom feedback notes, tracked in the SQL `payments.status` column.

---

## 🎨 UI/UX Design: Intuitive Claymorphism & Retractable Sidebar

- **Matte Textures & Soft Pastels**: Soft pastel canvas (`--clay-bg: #e6ebf5`) with layered ambient highlights.
- **Thick Rounded Corners**: Heavy `border-radius: 30px` across cards, containers, folders, and inputs.
- **Layered 3D Elevation**: Layered outer drop shadows with inner counter-shadows (`inset`) creating extruded tactile volume.
- **Sidebar Toggle Placement Strictly Inside Sidebar**:
  - The toggle button (`☰`) is placed **strictly inside the sidebar itself**, not in the top header or category tabs.
  - Expands and collapses the sidebar smoothly on desktop (`.retracted`) and mobile (`.mobile-open` with backdrop overlay) with exactly one unified toggle control.
- **Landlord Header Elements**:
  - Placed in the **upper right corner** of the interface:
    - **Refresh Button**: Compact `🔄 Refresh` button triggers live analytics recalculation.
    - **Landlord Profile**: Name **"Lead Dimaculangan"** with the title **"Landlord"** directly below it in a smaller, elegant font size.
    - Landlord avatar badge.
- **Dashboard Summary Widget**:
  - Does **not** display unit occupancy fractions (e.g. no "3/3 units occupied").
  - Features a clean summary widget updated by Vanilla JavaScript showing **only the total number of tenants currently residing in the apartment** (e.g., `👥 2 Active Residents`).
- **Tenant Directory (3 Claymorphic Folders)**:
  - Clicking the Tenant Directory in the sidebar renders **3 claymorphic folders** (`Unit 101`, `Unit 102`, `Unit 103`).
  - Clicking a specific folder opens that unit's detail view displaying personal records, emergency contacts, move-in dates, and the **"🗑️ Delete Account (Moved Out)"** button.
  - Smooth **"← Back to Folders"** button returns to the folder grid.
- **Manual Payment Verification & Audit**:
  - Dedicated section for the Landlord featuring:
    - Pending verification queue with direct inspection links and quick review buttons.
    - Modal dialog to inspect receipt proof, write verification notes, and approve or reject.
    - Full historical payments ledger with color-coded status badges (`APPROVED`, `PENDING`, `REJECTED`, `OVERDUE`).
- **Tenant Resident Portal**:
  - Single retractable sidebar neatly separating:
    1. **Dashboard** (Residency overview, monthly rent status, resolved work orders)
    2. **Log Maintenance** (Work order form with category selection, priority pills, and issue description)
    3. **Upload Payment** (Receipt upload drop-zone with file validation, progress track, status badges, and landlord feedback notes)

---

## 🗄️ SQL Schema (`database/schema.sql`)

- `units`: Exactly 3 units (`Unit 101`, `Unit 102`, `Unit 103`) with rent in `₱`.
- `users`: Role-based authentication (`landlord`, `tenant`), foreign key (`unit_id`), contact details, and bcrypt hashed credentials.
- `payments`: Monthly billing cycles, payments, receipt storage, verification notes, and `status` (`'pending'`, `'approved'`, `'rejected'`, `'overdue'`).
- `maintenance_tickets`: Unit-level work orders, trade disciplines, priority, and actual repair invoice costs in `₱`.

---

## 🚀 How to Set Up & Run with MySQL

### 1. Database Setup
1. Start **MySQL** in your XAMPP Control Panel.
2. Import `database/schema.sql` via **phpMyAdmin** (`http://localhost/phpmyadmin`) or Terminal:
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   *(Creates the `rentconnect` database with all tables, constraints, and verified seed data).*

3. Connection config is in [`config/db.php`](config/db.php) (host: `127.0.0.1`, user: `root`, password: ``, port: `3306`).

### 2. Start the Application
Run in your VS Code terminal or PowerShell:
```powershell
& "C:\xampp\php\php.exe" -S localhost:8000
```

### 3. Log In & Explore
Open your browser to:
👉 **http://localhost:8000**

- **Log in as Landlord**:
  - Username: `admin` | Password: `admin123`
  - See upper right header: **Lead Dimaculangan** with **Landlord** directly below and the **🔄 Refresh** button.
  - Notice the summary widget: **2 Active Residents** (no unit fractions).
  - Open the **Tenant Directory** to view the **3 Folders** (`Unit 101`, `Unit 102`, `Unit 103`).
- **Log in as Tenant**:
  - Username: `tenant1` | Password: `111111` (Unit 101)
  - Username: `tenant2` | Password: `222222` (Unit 102)
  - Navigate the retractable sidebar across **Dashboard**, **Log Maintenance**, and **Upload Payment**.
