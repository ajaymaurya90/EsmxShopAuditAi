# EsmxShopAuditAi

EsmxShopAuditAi is a Shopware 6 administration plugin that analyzes the operational health of a Shopware store and presents actionable insights directly inside the Shopware Administration.

The plugin performs automated audit scans of product data and store configuration, identifies issues that may affect sales or store performance, and generates structured findings and tasks for merchants or store managers.

The goal of this plugin is to provide a clear operational overview of the shop, highlight critical issues early, and guide users towards improving store quality and performance.

---

## Features

### Dashboard Overview

The Dashboard provides a centralized overview of the current store health and the most relevant operational insights.

Features include:

- Store health score based on audit results
- Animated health indicator for quick visual feedback
- Overview of scanned products and detected issues
- Quick access to the most important tasks
- AI-style "Next Best Action" recommendation
- Top tasks generated from audit findings
- Sales insights including:
    - Revenue
    - Orders
    - Revenue change
    - Order change
- Top selling products
- Low stock bestsellers
- Issue group summary cards for quick navigation

---

### Findings Management

The Findings page lists all detected audit issues and groups them by severity and category.

Capabilities include:

- Complete list of detected audit findings
- Severity-based filtering
- Sorting by category, severity, title, or affected entities
- Quick navigation to affected product records
- Detailed sections showing affected entities
- Pagination for detailed findings lists

This allows merchants to quickly understand what problems exist in the store and where corrective action is required.

---

### Task Management

The Tasks page converts audit findings into actionable operational tasks.

Features include:

- Task overview metrics
- Priority-based task classification
- Task status management
- Filtering by priority and status
- Affected entity counts for each task
- Sorting capabilities for better task prioritization

This allows teams to organize and address store issues systematically.

---

### Reports and Audit History

The Reports page provides a historical view of audit scans.

Features include:

- Scan history with pagination
- Scan result metrics
- Comparison with previous scans
- Selected report details
- Findings and tasks related to a specific scan
- Smooth navigation between reports and report details

This helps store operators monitor improvements over time and track the impact of optimizations.

---

### Store Health Score

The plugin calculates a store health score based on multiple operational criteria such as:

- Out of stock products
- Missing product prices
- Missing product descriptions
- Missing SEO metadata
- Missing images
- Missing category assignments
- Missing translations
- Critical issue groups

The score helps quickly evaluate the operational quality of the store.

---

### Navigation Integration

The plugin integrates directly into the Shopware Administration navigation and provides the following sections:

- Dashboard
- Findings
- Tasks
- Reports
- Settings

Each section is designed to provide focused insights and operational guidance.

---

## Architecture

The plugin is implemented as a Shopware 6 administration module with the following architecture components:

Frontend (Administration):

- Vue-based Shopware administration components
- Modular page structure
- Shared UI styling and components
- Data grids and analytical widgets

Backend:

- Plugin services responsible for audit logic
- API endpoints consumed by the administration module
- Scan execution and audit result processing

The architecture is designed to allow future expansion with additional audit rules and advanced analytics.

---

## Installation

Install the plugin like any standard Shopware plugin.


After installation the plugin will appear in the Shopware Administration menu.

---

## Configuration

The plugin provides configuration options in the Settings page.

Key configuration options include:

### Variant Audit Mode

Two modes are available:

**Effective Mode (default)**

Audits effective storefront product data including parent inheritance.  
This reflects what customers actually see in the storefront.

**Raw Mode**

Audits raw variant records without inheritance logic.

---

## Permissions

The plugin operates inside the Shopware Administration and requires access to:

- Product data
- Product variants
- Product metadata
- Sales insights data

No storefront functionality is modified by this plugin.

---

## Future Roadmap

Planned improvements include:

- Additional audit rule sets
- Automated fix suggestions
- AI-powered optimization insights
- SEO audit extensions
- Performance diagnostics
- Scheduled background scans
- SaaS integration for advanced analytics

---

## License

This plugin is distributed under the MIT License unless stated otherwise.

---

## Maintainer

EsmxShopAuditAi is maintained as part of the Esmx plugin ecosystem.
