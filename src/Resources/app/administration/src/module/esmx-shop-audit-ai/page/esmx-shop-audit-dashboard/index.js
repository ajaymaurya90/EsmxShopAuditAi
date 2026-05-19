import template from "./esmx-shop-audit-dashboard.html.twig";
import "./esmx-shop-audit-dashboard.scss";
import "../../shared/esmx-shop-audit-shared.scss";
import { buildSummaryCards } from "./constants/summary-cards.constant";
import {
  goToReports,
  goToFindings,
  goToTasks,
} from "../../core/utils/navigation.util";
import {
  formatLatestScanDate,
  formatCurrency,
  formatPercent,
  getFindingTitleByCode,
  getDynamicTaskTitle,
  getPriorityLabel,
  getStatusLabel,
  getSeverityLabel as resolveSeverityLabel,
} from "../../core/utils/format.util";
import {
  SEVERITY_WEIGHT,
  DEFAULT_SEVERITY_WEIGHT,
} from "../../core/constants/severity.constant";
import {
  loadScanOptions,
  normalizeScanOptions,
  applyScanCapabilities,
  saveScanOptions,
} from "../../core/utils/scan-options.util";
import {
  DEFAULT_SCAN_OPTIONS,
  SCAN_OPTION_GROUP_ICONS,
  SCAN_OPTION_GROUP_TOOLTIP_KEYS,
  SCAN_OPTION_TOOLTIP_KEYS,
} from "../../core/constants/scan-options.constant";

Shopware.Component.register("esmx-shop-audit-dashboard", {
  template,

  inject: ["esmxShopAuditApiService"],

  mixins: [Shopware.Mixin.getByName("notification")],

  data() {
    return {
      isLoading: false,
      isRunningScan: false,
      dashboard: null,
      latestScan: null,
      loadError: null,
      scanError: null,
      activeImpactKey: null,
      activeWidgetTooltip: null,
      affectedProducts: 0,
      animatedHealthScore: 0,
      isScanOptionsModalOpen: false,
      scanOptionsDraft: null,
      collapsedScanOptionGroups: {},
    };
  },

  computed: {
    hasCompletedScan() {
      return !!this.latestScan?.id;
    },

    totals() {
      return (
        this.dashboard?.scanAudit?.totals ??
        this.dashboard?.liveAudit?.totals ??
        {}
      );
    },

    meta() {
      return (
        this.dashboard?.scanAudit?.meta ?? this.dashboard?.liveAudit?.meta ?? {}
      );
    },

    insights() {
      return this.dashboard?.insights ?? {};
    },

    entityStats() {
      return (
        this.dashboard?.scanAudit?.entityStats ??
        this.dashboard?.insights?.latestSummary?.entityStats ?? {
          products: { affected: 0, scanned: 0 },
          categories: { affected: 0, scanned: 0 },
          cmsPages: { affected: 0, scanned: 0 },
        }
      );
    },

    topTasks() {
      return this.insights.topTasks ?? [];
    },

    scanCapabilities() {
      return this.dashboard?.scanCapabilities ?? {
        enabled: true,
        groups: {
          productHealth: { enabled: true },
          seo: { enabled: true },
          brokenLinks: { enabled: true },
          sales: { enabled: true },
          customerAudit: { enabled: true },
        },
      };
    },

    openTaskCount() {
      return this.insights.openTaskCount ?? 0;
    },

    scanOverviewStats() {
      return [
        {
          key: "affectedProducts",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboardInsights.affectedProducts",
          ),
          value: this.formatEntityStat(this.entityStats.products),
        },
        {
          key: "affectedCategories",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboardInsights.affectedCategories",
          ),
          value: this.formatEntityStat(this.entityStats.categories),
        },
        {
          key: "affectedCmsPages",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboardInsights.affectedCmsPages",
          ),
          value: this.formatEntityStat(this.entityStats.cmsPages),
        },
        {
          key: "totalIssues",
          label: this.$tc("esmx-shop-audit-ai.dashboardInsights.detectedIssues"),
          value: `${this.totals.totalIssues || 0} / ${this.activeSummaryCards.length}`,
        },
        {
          key: "criticalIssues",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboardInsights.criticalIssues",
          ),
          value: this.criticalIssuesCount,
        },
        {
          key: "openTasks",
          label: this.$tc("esmx-shop-audit-ai.dashboardInsights.openTasks"),
          value: this.openTaskCount,
        },
        {
          key: "abandonedCartCustomers",
          label: this.$tc("esmx-shop-audit-ai.dashboardInsights.abandonedCartCustomers"),
          value: this.customerStats.abandonedCarts?.affected || 0,
        },
        {
          key: "potentialRevenue",
          label: this.$tc("esmx-shop-audit-ai.dashboardInsights.potentialRevenue"),
          value: this.formatCurrency(this.customerStats.abandonedCarts?.potentialRevenue || 0),
        },
      ];
    },

    summaryCards() {
      return buildSummaryCards(this.$tc.bind(this), this.totals);
    },

    salesInsights() {
      return this.dashboard?.salesInsights ?? {};
    },

    customerStats() {
      return this.dashboard?.scanAudit?.customerStats
        ?? this.dashboard?.insights?.latestSummary?.customerStats
        ?? { abandonedCarts: { affected: 0, potentialRevenue: 0 } };
    },

    salesKpis() {
      return {
        revenue: Number(this.salesInsights?.kpis?.revenue ?? 0),
        orders: Number(this.salesInsights?.kpis?.orders ?? 0),
        revenueChange: Number(this.salesInsights?.kpis?.revenueChange ?? 0),
        ordersChange: Number(this.salesInsights?.kpis?.ordersChange ?? 0),
      };
    },

    topSellingProducts() {
      return this.salesInsights?.topProducts ?? [];
    },

    lowStockProducts() {
      return this.salesInsights?.lowStockBestSellers ?? [];
    },

    formattedLatestScanDate() {
      return formatLatestScanDate(this.latestScan);
    },

    activeSummaryCards() {
      return this.summaryCards.filter((card) => (card.count || 0) > 0);
    },

    sortedSummaryCards() {
      return [...this.activeSummaryCards].sort((a, b) => {
        const severityDiff =
          (SEVERITY_WEIGHT[b.severity] || DEFAULT_SEVERITY_WEIGHT) -
          (SEVERITY_WEIGHT[a.severity] || DEFAULT_SEVERITY_WEIGHT);

        if (severityDiff !== 0) {
          return severityDiff;
        }

        return (b.count || 0) - (a.count || 0);
      });
    },

    criticalIssuesCount() {
      return this.insights.criticalIssues || 0;
    },

    highIssuesCount() {
      return this.insights.highIssues || 0;
    },

    dashboardHeadline() {
      if (!this.hasHealthScore) {
        return this.$tc("esmx-shop-audit-ai.dashboard.healthNoChecksAction");
      }

      const outOfStock = this.totals.outOfStockProducts || 0;
      const missingDescription =
        this.totals.product_description || this.totals.missingDescription || 0;
      const missingMetaTitle =
        this.totals.product_meta_title || this.totals.missingMetaTitle || 0;
      const missingPrice = this.totals.missingPrice || 0;

      if (outOfStock > 0) {
        return this.$tc(
          "esmx-shop-audit-ai.dashboard.headlineOutOfStock",
          outOfStock,
          {
            count: outOfStock,
          },
        );
      }

      if (missingPrice > 0) {
        return this.$tc(
          "esmx-shop-audit-ai.dashboard.headlineMissingPrice",
          missingPrice,
          {
            count: missingPrice,
          },
        );
      }

      if (missingDescription > 0) {
        return this.$tc(
          "esmx-shop-audit-ai.dashboard.headlineMissingDescription",
          missingDescription,
          {
            count: missingDescription,
          },
        );
      }

      if (missingMetaTitle > 0) {
        return this.$tc(
          "esmx-shop-audit-ai.dashboard.headlineMissingMetaTitle",
          missingMetaTitle,
          {
            count: missingMetaTitle,
          },
        );
      }

      return this.$tc("esmx-shop-audit-ai.dashboard.healthyHeadline");
    },

    nextBestAction() {
      if (!this.hasHealthScore) {
        return null;
      }

      if (!this.activeSummaryCards.length) {
        return null;
      }

      const bestCard = [...this.activeSummaryCards]
        .map((card) => ({
          ...card,
          priorityScore: this.getCardPriorityScore(card),
        }))
        .sort((a, b) => b.priorityScore - a.priorityScore)[0];

      if (!bestCard) {
        return null;
      }

      return {
        label: this.getNextBestActionLabel(bestCard),
        code: bestCard.key,
      };
    },

    healthScore() {
      return this.dashboard?.health?.score ?? null;
    },

    hasHealthScore() {
      return this.healthScore !== null && this.healthScore !== undefined;
    },

    healthScoreDisplay() {
      return this.hasHealthScore
        ? this.animatedHealthScore
        : this.$tc("esmx-shop-audit-ai.dashboard.healthScoreNotAvailable");
    },

    healthStatus() {
      if (!this.hasHealthScore) {
        return "not_available";
      }

      if (this.criticalIssuesCount > 0) {
        return "critical";
      }

      if (this.highIssuesCount > 0) {
        return "needs_attention";
      }

      if (this.healthScore < 70) {
        return "needs_improvement";
      }

      return "good";
    },

    healthStatusLabel() {
      const labels = {
        good: this.$tc("esmx-shop-audit-ai.dashboard.healthStatus.good"),
        needs_attention: this.$tc(
          "esmx-shop-audit-ai.dashboard.healthStatus.needs_attention",
        ),
        needs_improvement: this.$tc(
          "esmx-shop-audit-ai.dashboard.healthStatus.needs_improvement",
        ),
        critical: this.$tc(
          "esmx-shop-audit-ai.dashboard.healthStatus.critical",
        ),
        not_available: this.$tc(
          "esmx-shop-audit-ai.dashboard.healthStatus.not_available",
        ),
      };

      return labels[this.healthStatus] || labels.not_available;
    },

    healthSummaryText() {
      if (!this.hasHealthScore) {
        return this.$tc(
          "esmx-shop-audit-ai.dashboard.healthSummaryNotAvailable",
        );
      }

      if (this.healthStatus === "good") {
        return this.$tc("esmx-shop-audit-ai.dashboard.healthSummaryGood");
      }

      if (this.healthStatus === "needs_attention") {
        return this.$tc("esmx-shop-audit-ai.dashboard.healthSummaryWarning");
      }

      if (this.healthStatus === "needs_improvement") {
        return this.$tc("esmx-shop-audit-ai.dashboard.healthSummaryNeedsImprovement");
      }

      return this.$tc("esmx-shop-audit-ai.dashboard.healthSummaryCritical");
    },

    healthRingStyle() {
      if (!this.hasHealthScore) {
        return {
          background:
            "conic-gradient(#d1d5db 0deg 360deg, #e5e7eb 0deg 360deg)",
        };
      }

      const score = Math.max(0, Math.min(this.animatedHealthScore, 100));
      let angle = Math.round((score / 100) * 360);

      if (score === 0) {
        angle = 6;
      }

      let color = "#10b981";

      if (this.healthStatus === "needs_attention") {
        color = "#f59e0b";
      }

      if (this.healthStatus === "needs_improvement") {
        color = "#fbbf24";
      }

      if (this.healthStatus === "critical") {
        color = "#ef4444";
      }

      return {
        background: `conic-gradient(${color} 0deg ${angle}deg, #e5e7eb ${angle}deg 360deg)`,
      };
    },

    seoMeta() {
      return (
        this.meta?.seo || {
          totalProducts: 0,
          productsNeedingImprovement: 0,
          averageOverallScore: 0,
          improvementThreshold: 0,
          improvementRate: 0,
        }
      );
    },

    latestScanOptions() {
      return (
        this.latestScan?.summaryJson?.scanOptions ??
        this.dashboard?.scanAudit?.scanOptions ??
        this.insights.latestSummary?.scanOptions ??
        null
      );
    },

    isSeoIncludedInLatestScan() {
      if (!this.hasCompletedScan) {
        return false;
      }

      const seoOptions = this.latestScanOptions?.seo;

      if (!seoOptions) {
        return true;
      }

      if (seoOptions.enabled === false) {
        return false;
      }

      const checks = seoOptions.checks || {};

      return Object.values(checks).some((enabled) => enabled === true);
    },

    seoKpiCards() {
      return [
        {
          key: "averageOverallScore",
          label: this.$tc("esmx-shop-audit-ai.dashboard.seoAverageScore"),
          value: `${this.seoMeta.averageOverallScore || 0}/100`,
          hint: this.$tc("esmx-shop-audit-ai.dashboard.seoAverageScoreHint"),
        },
        {
          key: "productsNeedingImprovement",
          label: this.$tc("esmx-shop-audit-ai.dashboard.seoNeedsImprovement"),
          value: `${this.seoMeta.productsNeedingImprovement || 0} / ${this.seoMeta.totalProducts || 0}`,
          hint: this.$tc(
            "esmx-shop-audit-ai.dashboard.seoNeedsImprovementHint",
            0,
            {
              threshold: this.seoMeta.improvementThreshold || 0,
            },
          ),
        },
        {
          key: "improvementRate",
          label: this.$tc("esmx-shop-audit-ai.dashboard.seoImprovementRate"),
          value: this.formatPercent(this.seoMeta.improvementRate),
          hint: this.$tc("esmx-shop-audit-ai.dashboard.seoImprovementRateHint"),
        },
      ];
    },

    scanOptionGroups() {
      return [
        {
          key: "productHealth",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboard.scanOptions.groups.productHealth",
          ),
          checks: [
            {
              key: "missingCoverImage",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.missingCoverImage",
              ),
            },
            {
              key: "inactiveProducts",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.inactiveProducts",
              ),
            },
            {
              key: "outOfStockProducts",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.outOfStockProducts",
              ),
            },
            {
              key: "missingCategory",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.missingCategory",
              ),
            },
            {
              key: "missingManufacturer",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.missingManufacturer",
              ),
            },
            {
              key: "missingPrice",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.missingPrice",
              ),
            },
            {
              key: "missingTranslation",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.missingTranslation",
              ),
            },
          ],
        },
        {
          key: "seo",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboard.scanOptions.groups.seo",
          ),
          checks: [
            {
              key: "product_name",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.productName",
              ),
            },
            {
              key: "product_description",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.productDescription",
              ),
            },
            {
              key: "product_meta_title",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.productMetaTitle",
              ),
            },
            {
              key: "product_meta_description",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.productMetaDescription",
              ),
            },
            {
              key: "category_missing_meta_title",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.categoryMetaTitle",
              ),
            },
            {
              key: "category_missing_meta_description",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.categoryMetaDescription",
              ),
            },
            {
              key: "category_missing_description",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.categoryDescription",
              ),
            },
          ],
        },
        {
          key: "brokenLinks",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboard.scanOptions.groups.brokenLinks",
          ),
          checks: [
            {
              key: "product_description",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.brokenLinkProductDescriptions",
              ),
            },
            {
              key: "category_description",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.brokenLinkCategoryDescriptions",
              ),
            },
            {
              key: "cms_content",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.brokenLinkCmsPages",
              ),
            },
          ],
        },
        {
          key: "sales",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboard.scanOptions.groups.sales",
          ),
          checks: [
            {
              key: "salesKpis",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.salesKpis",
              ),
            },
            {
              key: "topSellingProducts",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.topSellingProducts",
              ),
            },
            {
              key: "lowStockBestSellers",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.lowStockBestSellers",
              ),
            },
          ],
        },
        {
          key: "customerAudit",
          label: this.$tc(
            "esmx-shop-audit-ai.dashboard.scanOptions.groups.customerAudit",
          ),
          checks: [
            {
              key: "abandonedCartCustomers",
              label: this.$tc(
                "esmx-shop-audit-ai.dashboard.scanOptions.checks.abandonedCartCustomers",
              ),
            },
          ],
        },
      ];
    },

    selectedScanOptionCheckCount() {
      if (!this.scanOptionsDraft) {
        return 0;
      }

      return this.scanOptionGroups.reduce(
        (count, group) =>
          count +
          group.checks.filter(
            (check) =>
              this.scanOptionsDraft?.[group.key]?.checks?.[check.key] === true,
          ).length,
        0,
      );
    },

    selectedScanOptionGroupCount() {
      return this.scanOptionGroups.filter((group) =>
        this.isScanOptionGroupChecked(group.key),
      ).length;
    },

    scanOptionSummaryText() {
      const checksLabel = this.$tc(
        "esmx-shop-audit-ai.dashboard.scanOptions.summaryChecks",
        this.selectedScanOptionCheckCount,
        { count: this.selectedScanOptionCheckCount },
      );

      const groupsLabel = this.$tc(
        "esmx-shop-audit-ai.dashboard.scanOptions.summaryGroups",
        this.selectedScanOptionGroupCount,
        { count: this.selectedScanOptionGroupCount },
      );

      return `${checksLabel} ${this.$tc("esmx-shop-audit-ai.dashboard.scanOptions.summaryAcross")} ${groupsLabel}`;
    },
  },

  created() {
    this.initializeDashboard();

    if (this.$route.query.scanOptions === "1") {
      this.$nextTick(() => {
        this.openScanOptionsModal();
      });
    }
  },

  methods: {
    formatEntityStat(stat = {}) {
      return `${Number(stat.affected || 0)} / ${Number(stat.scanned || 0)}`;
    },

    initializeDashboard() {
      this.loadError = null;
      this.scanError = null;

      this.refreshDashboard().catch(() => {
        // handled below
      });
    },

    refreshDashboard() {
      return Promise.all([this.loadDashboard(), this.loadLatestScan()]);
    },

    loadDashboard() {
      this.isLoading = true;

      return this.esmxShopAuditApiService
        .getDashboard()
        .then((response) => {
          this.dashboard = response;
          this.latestScan = response?.latestScan || this.latestScan;

          this.$nextTick(() => {
            this.animateHealthScore();
          });
        })
        .catch((error) => {
          console.error("EsmxShopAuditAi dashboard error:", error);
          this.loadError = this.$tc("esmx-shop-audit-ai.dashboard.loadError");
          throw error;
        })
        .finally(() => {
          this.isLoading = false;
        });
    },

    loadLatestScan() {
      return this.esmxShopAuditApiService
        .getLatestScan()
        .then((response) => {
          this.latestScan = response?.scan || null;
        })
        .catch((error) => {
          console.error("EsmxShopAuditAi latest scan error:", error);
          throw error;
        });
    },

    runScan() {
      return this.executeRunScan(
        applyScanCapabilities(loadScanOptions(), this.scanCapabilities),
      );
    },

    executeRunScan(scanOptions = null) {
      this.isRunningScan = true;
      this.scanError = null;

      this.esmxShopAuditApiService
        .runScan(scanOptions)
        .then(() => this.refreshDashboard())
        .then(() => {
          this.createNotificationSuccess({
            message: this.$tc("esmx-shop-audit-ai.dashboard.runScanSuccess"),
          });
        })
        .catch((error) => {
          console.error("EsmxShopAuditAi run scan error:", error);
          this.scanError = this.$tc(
            "esmx-shop-audit-ai.dashboard.runScanError",
          );

          this.createNotificationError({
            message: this.$tc("esmx-shop-audit-ai.dashboard.runScanError"),
          });
        })
        .finally(() => {
          this.isRunningScan = false;
        });
    },

    openScanOptionsModal() {
      this.scanOptionsDraft = applyScanCapabilities(
        normalizeScanOptions(loadScanOptions()),
        this.scanCapabilities,
      );
      this.collapsedScanOptionGroups = {};
      this.isScanOptionsModalOpen = true;
    },

    closeScanOptionsModal() {
      if (this.isRunningScan) {
        return;
      }

      this.isScanOptionsModalOpen = false;
      this.scanOptionsDraft = null;
    },

    selectAllScanOptions() {
      this.scanOptionGroups.forEach((group) => {
        if (!this.isScanOptionGroupGloballyEnabled(group.key)) {
          this.setScanOptionGroupEnabled(group.key, false);
          return;
        }

        this.setScanOptionGroupEnabled(group.key, true);
      });
    },

    resetScanOptionsDraft() {
      this.scanOptionsDraft = applyScanCapabilities(
        normalizeScanOptions(DEFAULT_SCAN_OPTIONS),
        this.scanCapabilities,
      );
    },

    saveAndRunScanWithOptions() {
      const savedOptions = saveScanOptions(
        applyScanCapabilities(this.scanOptionsDraft, this.scanCapabilities),
      );
      this.isScanOptionsModalOpen = false;
      this.scanOptionsDraft = null;

      return this.executeRunScan(savedOptions);
    },

    isScanOptionGroupChecked(groupKey) {
      const checks = this.scanOptionsDraft?.[groupKey]?.checks ?? {};
      const checkKeys = Object.keys(checks);

      return checkKeys.some((key) => checks[key] === true);
    },

    isScanOptionGroupFullyChecked(groupKey) {
      const checks = this.scanOptionsDraft?.[groupKey]?.checks ?? {};
      const checkKeys = Object.keys(checks);

      return (
        checkKeys.length > 0 && checkKeys.every((key) => checks[key] === true)
      );
    },

    isScanOptionGroupPartiallyChecked(groupKey) {
      return (
        this.isScanOptionGroupChecked(groupKey) &&
        !this.isScanOptionGroupFullyChecked(groupKey)
      );
    },

    setScanOptionGroupEnabled(groupKey, enabled) {
      if (!this.scanOptionsDraft?.[groupKey]) {
        return;
      }

      if (!this.isScanOptionGroupGloballyEnabled(groupKey)) {
        enabled = false;
      }

      this.scanOptionsDraft[groupKey].enabled = enabled;

      Object.keys(this.scanOptionsDraft[groupKey].checks || {}).forEach(
        (checkKey) => {
          this.scanOptionsDraft[groupKey].checks[checkKey] = enabled;
        },
      );
    },

    onScanOptionGroupToggle(groupKey) {
      if (!this.isScanOptionGroupGloballyEnabled(groupKey)) {
        return;
      }

      this.setScanOptionGroupEnabled(
        groupKey,
        !this.isScanOptionGroupChecked(groupKey),
      );
    },

    onScanOptionCheckToggle(groupKey, checkKey, value) {
      if (!this.scanOptionsDraft?.[groupKey]?.checks) {
        return;
      }

      if (!this.isScanOptionGroupGloballyEnabled(groupKey)) {
        this.setScanOptionGroupEnabled(groupKey, false);
        return;
      }

      this.scanOptionsDraft[groupKey].checks[checkKey] =
        typeof value === "boolean" ? value : !!value?.target?.checked;
      this.scanOptionsDraft[groupKey].enabled =
        this.isScanOptionGroupChecked(groupKey);
    },

    toggleScanOptionGroupCollapse(groupKey) {
      this.collapsedScanOptionGroups = {
        ...this.collapsedScanOptionGroups,
        [groupKey]: !this.collapsedScanOptionGroups[groupKey],
      };
    },

    isScanOptionGroupCollapsed(groupKey) {
      return this.collapsedScanOptionGroups[groupKey] === true;
    },

    isScanOptionGroupGloballyEnabled(groupKey) {
      return this.scanCapabilities?.enabled !== false
        && this.scanCapabilities?.groups?.[groupKey]?.enabled !== false;
    },

    getScanOptionGroupIcon(groupKey) {
      return SCAN_OPTION_GROUP_ICONS[groupKey] || "regular-check-circle";
    },

    getScanOptionGroupTooltipKey(groupKey) {
      const tooltipKey = SCAN_OPTION_GROUP_TOOLTIP_KEYS[groupKey] || groupKey;

      return `esmx-shop-audit-ai.scanOptions.groupTooltips.${tooltipKey}`;
    },

    getScanOptionTooltipKey(groupKey, checkKey) {
      const tooltipKey =
        SCAN_OPTION_TOOLTIP_KEYS[groupKey]?.[checkKey] || checkKey;

      return `esmx-shop-audit-ai.scanOptions.tooltips.${tooltipKey}`;
    },

    goToFindings() {
      return goToFindings(this.$router);
    },

    goToTasks() {
      return goToTasks(this.$router);
    },

    goToReports() {
      return goToReports(this.$router);
    },

    goToTaskFilter(task) {
      this.$router.push({
        name: "esmx.shop.audit.ai.tasks",
        query: {
          priority: task.priority,
          code: task.code,
        },
      });
    },

    getNextBestActionLabel(card) {
      const map = {
        outOfStockProducts: this.$tc(
          "esmx-shop-audit-ai.dashboard.nextActionRestock",
        ),
        missingPrice: this.$tc("esmx-shop-audit-ai.dashboard.nextActionPrice"),
        missingDescription: this.$tc(
          "esmx-shop-audit-ai.dashboard.nextActionDescriptions",
        ),
        missingMetaTitle: this.$tc(
          "esmx-shop-audit-ai.dashboard.nextActionSeo",
        ),
      };

      return map[card.key] || card.label;
    },

    getSeverityClass(severity) {
      return `esmx-shop-audit-dashboard__metric-card--${severity}`;
    },

    getSeverityLabel(severity) {
      return resolveSeverityLabel(this.$tc.bind(this), severity);
    },

    getCardPriorityScore(card) {
      const weight = SEVERITY_WEIGHT[card.severity] || DEFAULT_SEVERITY_WEIGHT;

      return (card.count || 0) * weight;
    },

    handleNextBestAction() {
      if (!this.nextBestAction?.code) {
        return;
      }

      this.$router.push({
        name: "esmx.shop.audit.ai.findings",
        query: {
          code: this.nextBestAction.code,
        },
      });
    },

    goToProductDetail(productId) {
      if (!productId) {
        return;
      }

      this.$router.push({
        name: "sw.product.detail",
        params: {
          id: productId,
        },
      });
    },

    formatCurrency(value) {
      return formatCurrency(value);
    },

    goToFindingFromCard(card) {
      if (!card?.code) {
        return;
      }

      this.$router.push({
        name: "esmx.shop.audit.ai.findings",
        query: {
          code: card.code,
        },
      });
    },

    animateHealthScore() {
      if (!this.hasHealthScore) {
        this.animatedHealthScore = 0;
        return;
      }

      const target = Math.max(0, Math.min(this.healthScore, 100));
      const duration = 900;
      const start = this.animatedHealthScore;
      const startTime = performance.now();

      const animate = (currentTime) => {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const easedProgress = 1 - Math.pow(1 - progress, 3);

        this.animatedHealthScore = Math.round(
          start + (target - start) * easedProgress,
        );

        if (progress < 1) {
          window.requestAnimationFrame(animate);
          return;
        }

        this.animatedHealthScore = target;
      };

      window.requestAnimationFrame(animate);
    },

    getHealthLabel(key) {
      const map = {
        outOfStockProducts: this.$tc(
          "esmx-shop-audit-ai.dashboard.outOfStockProducts",
        ),
        missingPrice: this.$tc("esmx-shop-audit-ai.dashboard.missingPrice"),
        inactiveProducts: this.$tc(
          "esmx-shop-audit-ai.dashboard.inactiveProducts",
        ),
        missingCoverImage: this.$tc(
          "esmx-shop-audit-ai.dashboard.missingCoverImage",
        ),
        missingCategory: this.$tc(
          "esmx-shop-audit-ai.dashboard.missingCategory",
        ),
        missingManufacturer: this.$tc(
          "esmx-shop-audit-ai.dashboard.missingManufacturer",
        ),
        missingTranslation: this.$tc(
          "esmx-shop-audit-ai.dashboard.missingTranslation",
        ),
        product_name: this.$tc("esmx-shop-audit-ai.dashboard.productName"),
        product_description: this.$tc(
          "esmx-shop-audit-ai.dashboard.productDescription",
        ),
        product_meta_title: this.$tc(
          "esmx-shop-audit-ai.dashboard.productMetaTitle",
        ),
        product_meta_description: this.$tc(
          "esmx-shop-audit-ai.dashboard.productMetaDescription",
        ),
        category_missing_meta_title: this.$tc(
          "esmx-shop-audit-ai.dashboard.categoryMissingMetaTitle",
        ),
        category_missing_meta_description: this.$tc(
          "esmx-shop-audit-ai.dashboard.categoryMissingMetaDescription",
        ),
        category_missing_description: this.$tc(
          "esmx-shop-audit-ai.dashboard.categoryMissingDescription",
        ),
        broken_links: this.$tc("esmx-shop-audit-ai.findingTitles.broken_links"),
        criticalIssues: this.$tc("esmx-shop-audit-ai.dashboard.criticalIssues"),
      };

      return map[key] || key;
    },

    formatPercent(value) {
      return formatPercent(value);
    },

    getFindingTitleByCode(code, fallbackTitle = "") {
      return getFindingTitleByCode(this.$tc.bind(this), code, fallbackTitle);
    },

    getDynamicTaskTitle(task) {
      return getDynamicTaskTitle(this.$tc.bind(this), task);
    },

    getPriorityLabel(priority) {
      return getPriorityLabel(this.$tc.bind(this), priority);
    },

    getStatusLabel(status) {
      return getStatusLabel(this.$tc.bind(this), status);
    },
  },
});
