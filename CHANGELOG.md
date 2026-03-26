# Changelog

All notable changes to the EsmxShopAuditAi plugin are documented in this file.

---

## Version 1.0.0

Initial release of the EsmxShopAuditAi Shopware administration plugin.

### Added

Dashboard

- Store health score calculation
- Animated health indicator
- Scan overview metrics
- Next best action recommendation
- Issue group summary cards
- Top tasks widget
- Sales insights widget
- Top selling products widget
- Low stock products widget

Findings Page

- List of audit findings
- Severity classification
- Category grouping
- Sorting and filtering
- Detailed affected entity view
- Pagination for large datasets
- Navigation to affected product records

Tasks Page

- Task overview metrics
- Task priority classification
- Status management
- Priority filtering
- Status filtering
- Task sorting
- Affected entity counts

Reports Page

- Audit scan history
- Paginated report list
- Report comparison metrics
- Selected report summary
- Findings linked to reports
- Tasks linked to reports

Audit Engine

- Product data audit rules
- Issue grouping logic
- Severity classification
- Task generation from findings

Configuration

- Variant audit mode setting
- Effective storefront audit mode
- Raw variant record audit mode

Administration UI

- Unified UI layout for all pages
- Shared styling system
- Widget tooltips and explanations
- Smooth navigation between sections

Localization

- Snippet-based translations
- English and German support

---

### Improved

Code Architecture

- Introduced shared utility modules for:
    - Date and time formatting
    - Label resolution (severity, task status, priority)
    - SEO reason handling
- Centralized navigation logic using shared navigation utilities
- Introduced structured constants for severity handling (order and weight)
- Reduced code duplication across dashboard, findings, tasks, and reports modules
- Improved maintainability by standardizing repeated logic across pages

Data Handling

- Standardized date/time formatting across all pages (timezone-safe handling)
- Unified severity normalization and sorting logic
- Centralized SEO reason mapping for consistent labeling across findings and tasks

User Interface

- Improved consistency across all pages (dashboard, findings, tasks, reports)
- Removed non-functional row action menus from report detail grids
- Standardized severity labels and ordering in filters and views
- Improved clarity of report detail sections

Navigation

- Standardized routing behavior across all modules
- Improved navigation consistency between dashboard, findings, tasks, reports, and settings

---

### Fixed

- Fixed incorrect scan time display caused by timezone mismatch (UTC vs local time)
- Fixed inconsistent severity sorting logic across different modules
- Fixed missing fallback handling for translated labels
- Removed unused or redundant UI elements (non-functional action menus)
- Cleaned up debug logs and redundant helper methods

---

## Future Improvements

Planned enhancements for upcoming versions:

- Additional audit rule sets
- Scheduled automatic scans
- Automated fix suggestions
- AI-powered optimization insights
- SEO audit extensions
- Performance diagnostics
- SaaS analytics integration