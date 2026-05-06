# ESMX Shop Audit AI

ESMX Shop Audit AI is a Shopware 6.7 administration plugin for auditing shop quality, product health, SEO readiness, broken links, sales signals, and operational tasks from one place inside the Shopware Administration.

The plugin helps an administrator understand what is wrong in the shop, where the affected records are, and which fixes should be handled first. It turns audit checks into dashboard KPIs, grouped findings, actionable tasks, and historical scan reports.

## What an Admin Can Do

With this plugin, an administrator can:

- Configure which audit areas are active.
- Run a manual scan from the Administration.
- Choose scan options before each run.
- See a high-level store health result on the dashboard.
- Review affected products, categories, CMS pages, and broken links.
- Understand detected issue groups and their severity.
- Convert audit findings into prioritized tasks.
- Open affected records for manual correction.
- Use supported auto-fix actions for selected tasks.
- Review historical reports for previous scans.
- Track whether shop quality improves over time.

## Plugin Configuration

Configuration is available in the Shopware plugin settings.

### General Settings

- **Enable audit checks**: Enables or disables the main audit logic.
- **Product audit limit per run**: Controls how many products are evaluated in one scan.
- **Variant audit mode**:
  - **Effective storefront data** checks inherited storefront values, matching what customers see.
  - **Raw variant records** checks values stored directly on variants.

### Product Health Audit

Controls product data checks such as missing cover images, inactive products, out-of-stock products, missing categories, missing manufacturers, missing prices, and missing translations.

### SEO Audit

Controls SEO quality checks for product names, descriptions, meta titles, meta descriptions, and category SEO/content fields.

Configurable thresholds include:

- Minimum product title length
- Minimum product description length
- SEO meta title minimum and maximum length
- SEO meta description minimum and maximum length
- SEO improvement threshold

### Broken Link Audit

Controls broken-link detection in product descriptions, category descriptions, and CMS page content.

Options include:

- Enable broken link detection
- Check external links
- Maximum links per scan
- HTTP request timeout

### Sales Audit

Enables sales-related dashboard insights such as revenue, order metrics, top selling products, and low-stock bestsellers.

## Dashboard

The Dashboard is the main overview page after a scan.

### Run Scan

The administrator can start a scan from the dashboard action bar. The split scan button also opens scan options, where individual audit groups and checks can be selected before running the scan.

Scan option groups include:

- Product health
- SEO
- Broken links
- Sales

The selected checks determine which entities are evaluated and which findings/tasks are generated.

### Scan Result Overview

After a scan, the dashboard shows a KPI bar with:

- Affected products / scanned products
- Affected categories / scanned categories
- Affected CMS pages / scanned CMS pages
- Detected issue items / active issue groups
- Critical issue groups
- Open tasks

This helps distinguish between how many entities were scanned and how many unique entities were actually affected by findings.

### Store Health

The Store Health widget gives a score out of 100 based on enabled audit checks and issue severity. It applies penalties for operational problems such as out-of-stock products, missing prices, missing SEO content, missing categories, and broken links.

The health tooltip explains which checks contributed to the score and how penalties were applied.

### Next Best Action

The dashboard highlights the most useful action to take next based on the current audit result. For example, it may recommend reviewing out-of-stock products, fixing missing prices, improving product descriptions, or handling SEO metadata.

### Top Tasks

The dashboard lists the most important current tasks, sorted by affected count and priority. Administrators can open the Tasks page from here to work through the task list.

### Sales Insights

When sales audit is enabled, the dashboard also shows sales-related context such as revenue, orders, top selling products, and low-stock bestsellers.

## Findings Page

The Findings page shows all issue groups detected in the latest scan.

Each finding represents a grouped problem, for example:

- Products without cover image
- Inactive products
- Out-of-stock products
- Products without price
- Products with SEO description issues
- Categories missing SEO metadata
- Broken links detected

Administrators can:

- Filter findings by severity.
- Sort findings by audit group, title, category, severity, or affected count.
- See how many items are affected in each group.
- Open detailed affected item lists.
- Navigate to affected product or category records where supported.

The Findings page is useful when the administrator wants to understand the full scan result before assigning or fixing work.

## Tasks Page

The Tasks page converts findings into actionable work items.

Each task is generated from a finding and includes:

- **Priority**: High, medium, or low based on issue type.
- **Affected count**: Number of affected items in that task.
- **Impact**: A weighted score based on affected count and business importance.
- **Status**: Current task state, such as open or resolved.

Administrators can:

- Filter tasks by priority and status.
- Sort tasks by title, priority, affected count, impact, or status.
- Select a task to view affected items.
- Open affected records through manual fix actions.
- Use auto-fix where supported.
- Apply auto-fix to all affected items when backend conditions allow it.

For SEO tasks, the detail grid can show SEO score, severity, and reason so administrators can understand why a product was flagged.

For broken-link tasks, affected rows show readable link/source information so the administrator can identify where the broken link was found.

## Reports Page

The Reports page provides historical audit results.

Administrators can:

- View previous scan runs.
- See scan status, date, scanned products, findings, and tasks.
- Open a report detail view for a selected scan.
- Review findings generated by that scan.
- Review tasks generated by that scan.
- Compare current shop quality with previous scans.
- Delete report history when cleanup is needed.

Reports are useful for checking progress over time, validating cleanup work, and understanding whether repeated scans are reducing problems.

## Audit Areas

### Product Health

Product health checks focus on operational product readiness:

- Missing cover image
- Inactive products
- Out-of-stock products
- Missing category assignment
- Missing manufacturer
- Missing price
- Missing translations

### SEO

SEO checks focus on product and category content quality:

- Product name quality
- Product description quality
- Product meta title quality
- Product meta description quality
- Category missing meta title
- Category missing meta description
- Category missing description

### Broken Links

Broken-link checks inspect configured content sources:

- Product descriptions
- Category descriptions
- CMS page content

Each broken link finding stores the source entity, source type, URL, HTTP status, and error details where available.

### Sales Insights

Sales insights provide business context alongside audit findings:

- Revenue
- Orders
- Revenue change
- Order change
- Top selling products
- Low-stock bestsellers

## Entity Statistics

Each new scan stores entity statistics in the scan summary:

```json
{
  "entityStats": {
    "products": {
      "affected": 12,
      "scanned": 16
    },
    "categories": {
      "affected": 4,
      "scanned": 9
    },
    "cmsPages": {
      "affected": 3,
      "scanned": 8
    }
  }
}
```

Affected counts are deduplicated by entity type and entity ID. This means the same product with multiple issues is counted once in affected products, while total detected issues still counts every issue item.

## Architecture Summary

### Administration Frontend

- Shopware Administration module
- Vue/Twig component pages
- Dashboard, Findings, Tasks, Reports navigation
- Shared formatting, navigation, scan option, and severity utilities
- Custom tables and Shopware data grids for detail views

### Backend

- Admin API controller for dashboard, scans, findings, tasks, reports, and task actions
- Manual scan runner
- Product audit service
- SEO audit service
- Broken-link audit service
- Sales insight service
- Finding builder
- Task builder
- Entity statistics builder

## Installation

Install and activate the plugin like a standard Shopware plugin. After installation, the ESMX Shop Audit AI module appears in the Shopware Administration menu.

## Permissions and Scope

The plugin works inside the Shopware Administration and reads shop data required for auditing, including products, categories, CMS pages, orders, and related metadata.

It does not modify storefront behavior. Data is changed only when an administrator explicitly uses a supported fix action.

## Roadmap Ideas

Possible future improvements include:

- Scheduled background scans
- More audit rule groups
- Additional auto-fix actions
- Deeper AI-generated recommendations
- Report comparisons and trends
- Exportable audit reports
- More entity types and storefront quality checks

## Maintainer

ESMX Shop Audit AI is maintained as part of the ESMX plugin ecosystem.
