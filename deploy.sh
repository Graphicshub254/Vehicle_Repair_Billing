#!/bin/bash

# Vehicle Repair Billing System - Automated Deployment Script
# This script organizes all 40 files into the proper directory structure
# Run this script with: bash deploy.sh

echo "=========================================="
echo "Vehicle Repair Billing System Deployment"
echo "=========================================="
echo ""

# Color codes for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get the script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$SCRIPT_DIR"

echo -e "${BLUE}Step 1: Creating directory structure...${NC}"

# Create main directories
mkdir -p "$PROJECT_ROOT/config"
mkdir -p "$PROJECT_ROOT/includes"
mkdir -p "$PROJECT_ROOT/auth"
mkdir -p "$PROJECT_ROOT/assets/css"
mkdir -p "$PROJECT_ROOT/assets/js"
mkdir -p "$PROJECT_ROOT/assets/images"
mkdir -p "$PROJECT_ROOT/uploads"
mkdir -p "$PROJECT_ROOT/invoices"

# Create module directories
mkdir -p "$PROJECT_ROOT/modules/dashboard"
mkdir -p "$PROJECT_ROOT/modules/vehicles"
mkdir -p "$PROJECT_ROOT/modules/jobs"
mkdir -p "$PROJECT_ROOT/modules/labor"
mkdir -p "$PROJECT_ROOT/modules/invoices"
mkdir -p "$PROJECT_ROOT/modules/quotations"
mkdir -p "$PROJECT_ROOT/modules/subcontracts"
mkdir -p "$PROJECT_ROOT/modules/parts"
mkdir -p "$PROJECT_ROOT/modules/analytics"
mkdir -p "$PROJECT_ROOT/modules/reports"
mkdir -p "$PROJECT_ROOT/modules/settings"

echo -e "${GREEN}✓ Directory structure created${NC}"
echo ""

echo -e "${BLUE}Step 2: Moving files to proper locations...${NC}"

# Function to move file if it exists
move_file() {
    local source="$1"
    local destination="$2"
    
    if [ -f "$source" ]; then
        mv "$source" "$destination"
        echo -e "${GREEN}✓${NC} Moved: $(basename $source) → $destination"
    else
        echo -e "${YELLOW}⚠${NC} Not found: $(basename $source)"
    fi
}

# Move core files
move_file "$PROJECT_ROOT/database_schema.sql" "$PROJECT_ROOT/database_schema.sql"
move_file "$PROJECT_ROOT/index.php" "$PROJECT_ROOT/index.php"
move_file "$PROJECT_ROOT/README.md" "$PROJECT_ROOT/README.md"
move_file "$PROJECT_ROOT/QUICK_START.md" "$PROJECT_ROOT/QUICK_START.md"
move_file "$PROJECT_ROOT/FOLDER_STRUCTURE.md" "$PROJECT_ROOT/FOLDER_STRUCTURE.md"

# Move config
move_file "$PROJECT_ROOT/config.php" "$PROJECT_ROOT/config/config.php"

# Move includes
move_file "$PROJECT_ROOT/header.php" "$PROJECT_ROOT/includes/header.php"
move_file "$PROJECT_ROOT/footer.php" "$PROJECT_ROOT/includes/footer.php"

# Move auth
move_file "$PROJECT_ROOT/login.php" "$PROJECT_ROOT/auth/login.php"
move_file "$PROJECT_ROOT/logout.php" "$PROJECT_ROOT/auth/logout.php"

# Move assets
move_file "$PROJECT_ROOT/style.css" "$PROJECT_ROOT/assets/css/style.css"
move_file "$PROJECT_ROOT/main.js" "$PROJECT_ROOT/assets/js/main.js"

# Move dashboard
move_file "$PROJECT_ROOT/dashboard.php" "$PROJECT_ROOT/modules/dashboard/dashboard.php"

# Move vehicles
move_file "$PROJECT_ROOT/vehicles.php" "$PROJECT_ROOT/modules/vehicles/vehicles.php"
move_file "$PROJECT_ROOT/vehicle_history.php" "$PROJECT_ROOT/modules/vehicles/vehicle_history.php"

# Move jobs
move_file "$PROJECT_ROOT/jobs.php" "$PROJECT_ROOT/modules/jobs/jobs.php"
move_file "$PROJECT_ROOT/job_details.php" "$PROJECT_ROOT/modules/jobs/job_details.php"

# Move labor
move_file "$PROJECT_ROOT/add_labor.php" "$PROJECT_ROOT/modules/labor/add_labor.php"
move_file "$PROJECT_ROOT/edit_labor.php" "$PROJECT_ROOT/modules/labor/edit_labor.php"

# Move invoices
move_file "$PROJECT_ROOT/generate_invoice.php" "$PROJECT_ROOT/modules/invoices/generate_invoice.php"
move_file "$PROJECT_ROOT/view_invoice.php" "$PROJECT_ROOT/modules/invoices/view_invoice.php"
move_file "$PROJECT_ROOT/invoices.php" "$PROJECT_ROOT/modules/invoices/invoices.php"
move_file "$PROJECT_ROOT/invoice_generate_full.php" "$PROJECT_ROOT/modules/invoices/generate_full_invoice.php"

# Move quotations
move_file "$PROJECT_ROOT/create_quotation.php" "$PROJECT_ROOT/modules/quotations/create_quotation.php"
move_file "$PROJECT_ROOT/quotations.php" "$PROJECT_ROOT/modules/quotations/quotations.php"
move_file "$PROJECT_ROOT/view_quotation.php" "$PROJECT_ROOT/modules/quotations/view_quotation.php"
move_file "$PROJECT_ROOT/approve_quotation.php" "$PROJECT_ROOT/modules/quotations/approve_quotation.php"
move_file "$PROJECT_ROOT/enter_supplier_invoice.php" "$PROJECT_ROOT/modules/quotations/enter_supplier_invoice.php"

# Move subcontracts
move_file "$PROJECT_ROOT/add_subcontract.php" "$PROJECT_ROOT/modules/subcontracts/add_subcontract.php"
move_file "$PROJECT_ROOT/subcontracts.php" "$PROJECT_ROOT/modules/subcontracts/subcontracts.php"
move_file "$PROJECT_ROOT/view_subcontract.php" "$PROJECT_ROOT/modules/subcontracts/view_subcontract.php"

# Move parts
move_file "$PROJECT_ROOT/parts_install_isuzu.php" "$PROJECT_ROOT/modules/parts/install_isuzu_parts.php"
move_file "$PROJECT_ROOT/parts_install_subcontract.php" "$PROJECT_ROOT/modules/parts/install_subcontract_parts.php"

# Move analytics
move_file "$PROJECT_ROOT/analytics_dashboard.php" "$PROJECT_ROOT/modules/analytics/dashboard.php"

# Move reports
move_file "$PROJECT_ROOT/advanced_reports.php" "$PROJECT_ROOT/modules/reports/reports.php"

# Move settings
move_file "$PROJECT_ROOT/settings_management.php" "$PROJECT_ROOT/modules/settings/settings.php"
move_file "$PROJECT_ROOT/user_management.php" "$PROJECT_ROOT/modules/settings/user_management.php"
move_file "$PROJECT_ROOT/audit_trail.php" "$PROJECT_ROOT/modules/settings/audit_trail.php"
move_file "$PROJECT_ROOT/profile.php" "$PROJECT_ROOT/modules/settings/profile.php"

echo ""
echo -e "${BLUE}Step 3: Setting permissions...${NC}"

# Set proper permissions
chmod 755 "$PROJECT_ROOT/uploads"
chmod 755 "$PROJECT_ROOT/invoices"

# Create .htaccess for uploads folder
cat > "$PROJECT_ROOT/uploads/.htaccess" << 'EOF'
# Deny access to all files in uploads folder
Order Deny,Allow
Deny from all
EOF

# Create .htaccess for invoices folder
cat > "$PROJECT_ROOT/invoices/.htaccess" << 'EOF'
# Deny direct access to invoice files
Order Deny,Allow
Deny from all
EOF

chmod 644 "$PROJECT_ROOT/uploads/.htaccess"
chmod 644 "$PROJECT_ROOT/invoices/.htaccess"

echo -e "${GREEN}✓ Permissions set${NC}"
echo ""

echo -e "${BLUE}Step 4: Creating configuration guide...${NC}"

# Create a setup checklist
cat > "$PROJECT_ROOT/INSTALLATION_CHECKLIST.md" << 'EOF'
# Installation Checklist

## 1. Database Setup
- [ ] Create MySQL database: `vehicle_repair_billing`
- [ ] Import schema: `mysql -u root -p vehicle_repair_billing < database_schema.sql`
- [ ] Verify all 17 tables created

## 2. Configuration
- [ ] Edit `config/config.php`
- [ ] Set DB_HOST (usually 'localhost')
- [ ] Set DB_USER (your MySQL username)
- [ ] Set DB_PASS (your MySQL password)
- [ ] Set APP_URL (your domain, e.g., 'http://localhost/vehicle_repair_billing')

## 3. Permissions
- [ ] Ensure `uploads/` is writable: `chmod 755 uploads/`
- [ ] Ensure `invoices/` is writable: `chmod 755 invoices/`

## 4. First Login
- [ ] Access: http://yourdomain.com/
- [ ] Username: admin
- [ ] Password: admin123
- [ ] **IMMEDIATELY change admin password!**

## 5. Initial Setup
- [ ] Update company information (Settings → Company Info)
- [ ] Configure VAT rate (Settings → Financial)
- [ ] Set default markup percentages
- [ ] Add suppliers (Settings → Suppliers)
- [ ] Add subcontractors (Settings → Subcontractors)
- [ ] Create user accounts (Settings → User Management)

## 6. Test System
- [ ] Create a test vehicle
- [ ] Create a test job
- [ ] Add labor charges
- [ ] Generate an invoice
- [ ] Print invoice
- [ ] Check analytics dashboard
- [ ] Review audit trail

## Security Reminders
- ✓ Change default admin password
- ✓ Use strong passwords for all users
- ✓ Regular database backups
- ✓ Keep software updated
- ✓ Monitor audit trail regularly
EOF

echo -e "${GREEN}✓ Installation checklist created${NC}"
echo ""

echo -e "${BLUE}Step 5: Final verification...${NC}"

# Count files in each directory
count_files() {
    local dir="$1"
    local count=$(find "$dir" -maxdepth 1 -type f -name "*.php" 2>/dev/null | wc -l)
    echo "$count"
}

echo ""
echo "Directory Status:"
echo "├── config/        $(count_files "$PROJECT_ROOT/config") files"
echo "├── includes/      $(count_files "$PROJECT_ROOT/includes") files"
echo "├── auth/          $(count_files "$PROJECT_ROOT/auth") files"
echo "├── assets/css/    $(count_files "$PROJECT_ROOT/assets/css") files"
echo "├── assets/js/     $(count_files "$PROJECT_ROOT/assets/js") files"
echo "└── modules/"
echo "    ├── dashboard/      $(count_files "$PROJECT_ROOT/modules/dashboard") files"
echo "    ├── vehicles/       $(count_files "$PROJECT_ROOT/modules/vehicles") files"
echo "    ├── jobs/           $(count_files "$PROJECT_ROOT/modules/jobs") files"
echo "    ├── labor/          $(count_files "$PROJECT_ROOT/modules/labor") files"
echo "    ├── invoices/       $(count_files "$PROJECT_ROOT/modules/invoices") files"
echo "    ├── quotations/     $(count_files "$PROJECT_ROOT/modules/quotations") files"
echo "    ├── subcontracts/   $(count_files "$PROJECT_ROOT/modules/subcontracts") files"
echo "    ├── parts/          $(count_files "$PROJECT_ROOT/modules/parts") files"
echo "    ├── analytics/      $(count_files "$PROJECT_ROOT/modules/analytics") files"
echo "    ├── reports/        $(count_files "$PROJECT_ROOT/modules/reports") files"
echo "    └── settings/       $(count_files "$PROJECT_ROOT/modules/settings") files"
echo ""

echo -e "${GREEN}=========================================="
echo "Deployment Complete!"
echo "==========================================${NC}"
echo ""
echo "Next Steps:"
echo "1. Review INSTALLATION_CHECKLIST.md"
echo "2. Set up database (see database_schema.sql)"
echo "3. Configure config/config.php"
echo "4. Access the system and change admin password"
echo ""
echo "Documentation:"
echo "- README.md - Complete system overview"
echo "- QUICK_START.md - Quick start guide"
echo "- INSTALLATION_CHECKLIST.md - Setup steps"
echo ""
echo -e "${YELLOW}Important: Change the default admin password immediately!${NC}"
echo ""
