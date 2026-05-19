import template from "./esmx-shop-audit-findings.html.twig";
import "../../shared/esmx-shop-audit-shared.scss";
import "./esmx-shop-audit-findings.scss";
import { getFindingImpact } from "./constants/finding-impact.constant";
import {
  formatLatestScanDate,
  formatAdminDateTime,
  formatCurrency,
  getFindingTitleByCode,
  getSeverityLabel,
  getSeoReasonLabel as resolveSeverityLabel,
} from "../../core/utils/format.util";
import {
  goToDashboard,
  goToTasks,
  goToReports,
} from "../../core/utils/navigation.util";
import {
  SEVERITY_ORDER,
  SEVERITY_WEIGHT,
  DEFAULT_SEVERITY_WEIGHT,
} from "../../core/constants/severity.constant";
import { loadScanOptions } from "../../core/utils/scan-options.util";

const PRODUCT_HEALTH_FINDING_CODES = [
  "missing_cover_image",
  "inactive_products",
  "out_of_stock_products",
  "missing_category",
  "missing_manufacturer",
  "missing_price",
  "missing_translation",
];

const SEO_FINDING_CODES = [
  "product_name",
  "product_description",
  "product_meta_title",
  "product_meta_description",
  "category_missing_meta_title",
  "category_missing_meta_description",
  "category_missing_description",
];

const BROKEN_LINK_FINDING_CODES = ["broken_links"];

const SALES_FINDING_CODES = [];
const CUSTOMER_AUDIT_FINDING_CODES = ["abandoned_cart_customers"];

Shopware.Component.register("esmx-shop-audit-findings", {
  template,

  inject: ["esmxShopAuditApiService"],

  data() {
    return {
      isLoading: false,
      isRunningScan: false,
      latestScan: null,
      findings: [],
      loadError: null,
      scanError: null,
      activeImpactCode: null,
      activeWidgetTooltip: null,
      sortBy: "severity",
      sortDirection: "desc",
      selectedSeverityFilters: [],
      isFilterMenuOpen: false,
      detailPageSize: 10,
      detailCurrentPages: {},
      detailSortBy: {},
      detailSortDirection: {},
    };
  },

  watch: {
    "$route.query.code"(newCode) {
      if (!newCode) {
        return;
      }

      this.$nextTick(() => {
        window.setTimeout(() => {
          this.scrollToFindingSection(newCode);
        }, 150);
      });
    },
  },

  computed: {
    pageTitle() {
      return this.$tc("esmx-shop-audit-ai.findings.pageTitle");
    },

    findingsBySeverity() {
      return SEVERITY_ORDER.reduce((result, severity) => {
        result[severity] = this.findings.filter((item) => {
          return (
            String(item.severity || "")
              .toLowerCase()
              .trim() === severity
          );
        }).length;

        return result;
      }, {});
    },

    totalAffectedCount() {
      return this.findings.reduce(
        (sum, item) => sum + (item.affectedCount || 0),
        0,
      );
    },

    activeCodeFilter() {
      return this.$route.query.code || null;
    },

    severityFilters() {
      return SEVERITY_ORDER.map((severity) => {
        const count = this.findings.filter((finding) => {
          return (
            String(finding.severity || "")
              .toLowerCase()
              .trim() === severity
          );
        }).length;

        return {
          key: severity,
          label: getSeverityLabel(this.$tc.bind(this), severity),
          count,
          disabled: count === 0,
        };
      });
    },

    filteredFindings() {
      if (!this.selectedSeverityFilters.length) {
        return this.findings;
      }

      return this.findings.filter((finding) => {
        const severity = String(finding.severity || "")
          .toLowerCase()
          .trim();

        return this.selectedSeverityFilters.includes(severity);
      });
    },

    filteredAffectedItemsCount() {
      return this.filteredFindings.reduce((sum, finding) => {
        return sum + Number(finding.affectedCount || 0);
      }, 0);
    },

    filteredIssueGroupsCount() {
      return this.filteredFindings.length;
    },

    hasActiveFilters() {
      return this.selectedSeverityFilters.length > 0;
    },

    sortedFindings() {
      const items = [...this.filteredFindings];

      items.sort((a, b) => {
        let result = 0;

        switch (this.sortBy) {
          case "auditGroup":
            result = this.getAuditGroupLabel(a).localeCompare(
              this.getAuditGroupLabel(b),
            );
            break;

          case "title":
            result = this.getFindingTitleByCode(a.code, a.title).localeCompare(
              this.getFindingTitleByCode(b.code, b.title),
            );
            break;

          case "category":
            result = this.getCategoryLabel(a.entity).localeCompare(
              this.getCategoryLabel(b.entity),
            );
            break;

          case "severity": {
            const severityA = String(a.severity || "")
              .toLowerCase()
              .trim();
            const severityB = String(b.severity || "")
              .toLowerCase()
              .trim();

            result =
              (SEVERITY_WEIGHT[severityA] || DEFAULT_SEVERITY_WEIGHT) -
              (SEVERITY_WEIGHT[severityB] || DEFAULT_SEVERITY_WEIGHT);
            break;
          }

          case "count":
            result =
              Number(a.affectedCount || 0) - Number(b.affectedCount || 0);
            break;

          default:
            result = 0;
        }

        return this.sortDirection === "asc" ? result : -result;
      });

      return items;
    },

    sortIconMap() {
      return {
        asc: "regular-chevron-up-s",
        desc: "regular-chevron-down-s",
      };
    },

    resultsSummaryText() {
      const items = this.filteredAffectedItemsCount;
      const groups = this.filteredIssueGroupsCount;

      if (!this.selectedSeverityFilters.length) {
        return `${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryPrefix")} ${items} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryMiddle")} ${groups} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummarySuffix")}`;
      }

      if (this.selectedSeverityFilters.length === 1) {
        const severityLabel = this.getSeverityLabel(
          this.selectedSeverityFilters[0],
        ).toLowerCase();

        return `${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryPrefix")}
                ${items} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryMiddle")}
                ${groups} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummarySuffix")} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryWithSeverityPrefix")}
                ${severityLabel} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryWithSeveritySuffix")}`;
      }

      return `${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryPrefix")} ${items} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryMiddle")} ${groups} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummarySuffix")} ${this.$tc("esmx-shop-audit-ai.findings.resultsSummaryWithSelectedFilters")}`;
    },

    formattedLatestScanDate() {
      return formatLatestScanDate(this.latestScan);
    },
  },

  created() {
    this.loadPageData();
  },

  methods: {
    loadPageData() {
      this.isLoading = true;
      this.loadError = null;

      this.esmxShopAuditApiService
        .getLatestFindings()
        .then((response) => {
          this.latestScan = response.scan;
          this.findings = (response.findings ?? []).map((finding) => {
            let payloadJson = finding.payloadJson || {};

            if (typeof payloadJson === "string") {
              try {
                payloadJson = JSON.parse(payloadJson);
              } catch (e) {
                payloadJson = {};
              }
            }

            const items = Array.isArray(finding.items)
              ? finding.items
              : Array.isArray(payloadJson.items)
                ? payloadJson.items
                : [];

            if (finding.code !== "broken_links") {
              return {
                ...finding,
                items,
                payloadJson,
              };
            }

            return {
              ...finding,
              payloadJson,
              items: items.map((item, index) => ({
                ...item,
                id: `broken-link-${finding.id}-${index}`,
                sourceEntityId: item.id,
                entity: item.entity || "-",
                name: item.name || "-",
                languageName: item.languageName || "-",
                source: item.source || "-",
                status: item.status ?? "-",
                url: item.url || "-",
              })),
            };
          });

          this.detailCurrentPages = {};
        })
        .then(() => {
          this.$nextTick(() => {
            if (this.activeCodeFilter) {
              window.setTimeout(() => {
                this.scrollToFindingSection(this.activeCodeFilter);
              }, 150);
            }
          });
        })
        .catch((error) => {
          console.error("EsmxShopAuditAi findings error:", error);
          this.loadError = this.$tc("esmx-shop-audit-ai.findings.loadError");
        })
        .finally(() => {
          this.isLoading = false;
        });
    },

    runScan() {
      this.isRunningScan = true;
      this.scanError = null;

      this.esmxShopAuditApiService
        .runScan(loadScanOptions())
        .then(() => this.loadPageData())
        .catch((error) => {
          console.error("EsmxShopAuditAi findings run scan error:", error);
          this.scanError = this.$tc(
            "esmx-shop-audit-ai.dashboard.runScanError",
          );
        })
        .finally(() => {
          this.isRunningScan = false;
        });
    },

    formatDate(value) {
      return formatAdminDateTime(value);
    },

    formatMoney(value) {
      return formatCurrency(value);
    },

    toggleFilterMenu() {
      this.isFilterMenuOpen = !this.isFilterMenuOpen;
    },

    toggleSeverityFilter(severity) {
      const normalized = String(severity).toLowerCase().trim();

      const filter = this.severityFilters.find(
        (item) => item.key === normalized,
      );

      if (filter?.disabled) {
        return;
      }

      if (this.selectedSeverityFilters.includes(normalized)) {
        this.selectedSeverityFilters = this.selectedSeverityFilters.filter(
          (item) => item !== normalized,
        );
        return;
      }

      this.selectedSeverityFilters = [
        ...this.selectedSeverityFilters,
        normalized,
      ];
    },

    clearSeverityFilters() {
      this.selectedSeverityFilters = [];
      this.isFilterMenuOpen = false;
    },

    isSeveritySelected(severity) {
      return this.selectedSeverityFilters.includes(
        String(severity).toLowerCase().trim(),
      );
    },

    toggleSort(column) {
      if (this.sortBy === column) {
        this.sortDirection = this.sortDirection === "asc" ? "desc" : "asc";
        return;
      }

      this.sortBy = column;

      if (
        column === "auditGroup" ||
        column === "title" ||
        column === "category"
      ) {
        this.sortDirection = "asc";
        return;
      }

      this.sortDirection = "desc";
    },

    isSortedBy(column) {
      return this.sortBy === column;
    },

    getCategoryLabel(entity) {
      if (!entity) {
        return this.$tc("esmx-shop-audit-ai.findings.unknownCategory");
      }

      const normalized = String(entity).toLowerCase();

      const labels = {
        product: this.$tc("esmx-shop-audit-ai.findings.categoryProduct"),
        category: this.$tc("esmx-shop-audit-ai.findings.categoryCategory"),
        cms_page: this.$tc("esmx-shop-audit-ai.findings.categoryCmsPage"),
        link: this.$tc("esmx-shop-audit-ai.findings.categoryBrokenLink"),
        customer: this.$tc("esmx-shop-audit-ai.findings.categoryCustomer"),
        order: this.$tc("esmx-shop-audit-ai.findings.categoryOrder"),
      };

      return labels[normalized] || entity;
    },

    getAuditGroupKey(finding) {
      const code = String(finding?.code || "").toLowerCase().trim();

      if (PRODUCT_HEALTH_FINDING_CODES.includes(code)) {
        return "productHealth";
      }

      if (SEO_FINDING_CODES.includes(code)) {
        return "seo";
      }

      if (BROKEN_LINK_FINDING_CODES.includes(code)) {
        return "brokenLinks";
      }

      if (SALES_FINDING_CODES.includes(code)) {
        return "sales";
      }

      if (CUSTOMER_AUDIT_FINDING_CODES.includes(code)) {
        return "customerAudit";
      }

      return "productHealth";
    },

    getAuditGroupLabel(finding) {
      const labels = {
        productHealth: this.$tc(
          "esmx-shop-audit-ai.findings.auditGroups.productHealth",
        ),
        seo: this.$tc("esmx-shop-audit-ai.findings.auditGroups.seo"),
        brokenLinks: this.$tc(
          "esmx-shop-audit-ai.findings.auditGroups.brokenLinks",
        ),
        sales: this.$tc("esmx-shop-audit-ai.findings.auditGroups.sales"),
        customerAudit: this.$tc(
          "esmx-shop-audit-ai.findings.auditGroups.customerAudit",
        ),
      };

      return labels[this.getAuditGroupKey(finding)] || labels.productHealth;
    },

    getAuditGroupIcon(finding) {
      const icons = {
        productHealth: "regular-products",
        seo: "regular-search",
        brokenLinks: "regular-link-horizontal-slash",
        sales: "regular-chart-bar",
        customerAudit: "regular-users",
      };

      return icons[this.getAuditGroupKey(finding)] || "regular-check-circle";
    },

    getImpactLabel(code) {
      return getFindingImpact(this.$tc.bind(this), code);
    },

    getSeverityLabel(severity) {
      return resolveSeverityLabel(this.$tc.bind(this), severity);
    },

    getSeverityClass(severity) {
      const normalized = String(severity || "")
        .toLowerCase()
        .trim();

      return `severity-${normalized}`;
    },

    scrollToFindingSection(code) {
      if (!code) {
        return;
      }

      const tryScroll = () => {
        const section = document.getElementById(`finding-section-${code}`);

        if (!section) {
          return false;
        }

        section.scrollIntoView({
          behavior: "smooth",
          block: "start",
        });

        return true;
      };

      if (tryScroll()) {
        return;
      }

      window.setTimeout(() => {
        tryScroll();
      }, 200);
    },

    goToFindingDetailSection(finding) {
      if (!finding?.code) {
        return;
      }

      this.scrollToFindingSection(finding.code);
    },

    goToDashboard() {
      return goToDashboard(this.$router);
    },

    openScanOptionsModal() {
      return goToDashboard(this.$router, { scanOptions: "1" });
    },

    goToTasks() {
      return goToTasks(this.$router);
    },

    goToReports() {
      return goToReports(this.$router);
    },

    getFindingItems(finding) {
      const items = finding?.items || finding?.payloadJson?.items || [];

      if (finding?.code !== "broken_links") {
        return items;
      }

      return items.map((item, index) => {
        return {
          ...item,
          id:
            item.rowId ||
            `${item.entity || "link"}-${item.id || index}-${item.languageId || "default"}-${index}`,
          url: item.url || "-",
          entity: item.entity || "-",
          name: item.name || "-",
          languageName: item.languageName || "-",
          source: item.source || "-",
          status: item.status ?? "-",
          error: item.error || "-",
        };
      });
    },

    getDetailCurrentPage(findingId) {
      return this.detailCurrentPages[findingId] ?? 1;
    },

    getDetailTotalPages(finding) {
      const items = this.getSortedFindingItems(finding);
      return Math.max(1, Math.ceil(items.length / this.detailPageSize));
    },

    getPaginatedFindingItems(finding) {
      const items = this.getSortedFindingItems(finding);
      const currentPage = this.getDetailCurrentPage(finding.id);
      const start = (currentPage - 1) * this.detailPageSize;
      const end = start + this.detailPageSize;

      return items.slice(start, end);
    },

    setDetailPage(findingId, page) {
      const safePage = Math.max(1, page);
      this.detailCurrentPages = {
        ...this.detailCurrentPages,
        [findingId]: safePage,
      };
    },

    goToDetailPrevPage(finding) {
      const currentPage = this.getDetailCurrentPage(finding.id);

      if (currentPage <= 1) {
        return;
      }

      this.setDetailPage(finding.id, currentPage - 1);
    },

    goToDetailNextPage(finding) {
      const currentPage = this.getDetailCurrentPage(finding.id);
      const totalPages = this.getDetailTotalPages(finding);

      if (currentPage >= totalPages) {
        return;
      }

      this.setDetailPage(finding.id, currentPage + 1);
    },

    openProductDetailInNewTab(item) {
      const productId = item?.productId || item?.sourceEntityId || item?.id;

      if (!productId) {
        return;
      }

      const resolved = this.$router.resolve({
        name: "sw.product.detail",
        params: {
          id: productId,
        },
      });

      if (resolved?.href) {
        window.open(resolved.href, "_blank");
      }
    },

    openCustomerDetailInNewTab(item) {
      const customerId = item?.customerId || item?.entityId || item?.id;

      if (!customerId) {
        return;
      }

      const resolved = this.$router.resolve({
        name: "sw.customer.detail",
        params: {
          id: customerId,
        },
      });

      if (resolved?.href) {
        window.open(resolved.href, "_blank");
      }
    },

    isSeoFieldFinding(finding) {
      const code = finding?.code || "";

      return [
        "product_name",
        "product_description",
        "product_meta_title",
        "product_meta_description",
      ].includes(code);
    },

    getFindingColumns(finding) {
      // BROKEN LINKS SUPPORT
      if (finding.code === "broken_links") {
        return [
          {
            property: "entity",
            label: this.$tc("esmx-shop-audit-ai.findings.columns.entity"),
            primary: true,
            sortable: true,
          },
          {
            property: "name",
            label: this.$tc("esmx-shop-audit-ai.findings.columns.name"),
            sortable: true,
          },
          {
            property: "languageName",
            label: this.$tc("esmx-shop-audit-ai.findings.columns.language"),
            sortable: true,
          },
          {
            property: "source",
            label: this.$tc("esmx-shop-audit-ai.findings.columns.source"),
            sortable: true,
          },
          {
            property: "status",
            label: this.$tc("esmx-shop-audit-ai.findings.columns.status"),
            sortable: true,
          },
          {
            property: "url",
            label: this.$tc("esmx-shop-audit-ai.findings.columns.url"),
          },
        ];
      }

      if (finding.code === "abandoned_cart_customers") {
        return [
          {
            property: "customerName",
            label: this.$tc("esmx-shop-audit-ai.customerAudit.columns.customer"),
            primary: true,
            sortable: true,
          },
          {
            property: "email",
            label: this.$tc("esmx-shop-audit-ai.customerAudit.columns.email"),
            sortable: true,
          },
          {
            property: "cartValue",
            label: this.$tc("esmx-shop-audit-ai.customerAudit.columns.cartValue"),
            sortable: true,
          },
          {
            property: "productCount",
            label: this.$tc("esmx-shop-audit-ai.customerAudit.columns.productCount"),
            sortable: true,
          },
          {
            property: "lastActivityAt",
            label: this.$tc("esmx-shop-audit-ai.customerAudit.columns.lastActivity"),
            sortable: true,
          },
          {
            property: "salesChannelName",
            label: this.$tc("esmx-shop-audit-ai.customerAudit.columns.salesChannel"),
            sortable: true,
          },
        ];
      }
      if (this.isSeoFieldFinding(finding)) {
        return [
          {
            property: "name",
            label: this.$tc("esmx-shop-audit-ai.grid.productName"),
            primary: true,
          },
          {
            property: "productNumber",
            label: this.$tc("esmx-shop-audit-ai.grid.productNumber"),
          },
          {
            property: "overallSeoScore",
            label: this.$tc("esmx-shop-audit-ai.findings.overallScore"),
          },
          {
            property: "reason",
            label: this.$tc("esmx-shop-audit-ai.findings.reasonLabel"),
          },
        ];
      }

      return [
        {
          property: "name",
          label: this.$tc("esmx-shop-audit-ai.grid.productName"),
          primary: true,
        },
        {
          property: "productNumber",
          label: this.$tc("esmx-shop-audit-ai.grid.productNumber"),
        },
        {
          property: "stock",
          label: this.$tc("esmx-shop-audit-ai.grid.stock"),
        },
      ];
    },

    getFindingTitleByCode(code, fallbackTitle = "") {
      return getFindingTitleByCode(this.$tc.bind(this), code, fallbackTitle);
    },

    getReasonLabel(reason) {
      return getSeoReasonLabel(this.$tc.bind(this), reason);
    },

    openBrokenLinkUrl(item) {
      if (!item?.url || item.url === "-") {
        return;
      }

      const url = item.url.startsWith("http")
        ? item.url
        : `${window.location.origin}${item.url}`;

      window.open(url, "_blank");
    },

    openAffectedEntityInNewTab(item) {
      if (item?.entity === "product") {
        return this.openProductDetailInNewTab(item);
      }

      if (item?.entity === "cms_page" && item?.sourceEntityId) {
        const resolved = this.$router.resolve({
          name: "sw.cms.detail",
          params: {
            id: item.sourceEntityId,
          },
        });

        if (resolved?.href) {
          window.open(resolved.href, "_blank");
        }
      }

      if (item?.entity === "category" && item?.sourceEntityId) {
        const resolved = this.$router.resolve({
          name: "sw.category.detail",
          params: {
            id: item.sourceEntityId,
          },
        });

        if (resolved?.href) {
          window.open(resolved.href, "_blank");
        }
      }

      if (item?.entity === "customer") {
        return this.openCustomerDetailInNewTab(item);
      }
    },

    getDetailSortBy(findingId) {
      return this.detailSortBy[findingId] || null;
    },

    getDetailSortDirection(findingId) {
      return this.detailSortDirection[findingId] || "asc";
    },

    toggleDetailSort(findingId, column) {
      const currentSortBy = this.getDetailSortBy(findingId);
      const currentDirection = this.getDetailSortDirection(findingId);

      this.detailSortBy = {
        ...this.detailSortBy,
        [findingId]: column,
      };

      this.detailSortDirection = {
        ...this.detailSortDirection,
        [findingId]:
          currentSortBy === column && currentDirection === "asc"
            ? "desc"
            : "asc",
      };
    },

    getSortedFindingItems(finding) {
      const items = [...this.getFindingItems(finding)];
      const sortBy = this.getDetailSortBy(finding.id);

      if (!sortBy) {
        return items;
      }

      const direction = this.getDetailSortDirection(finding.id);

      items.sort((a, b) => {
        const valueA = String(a[sortBy] ?? "").toLowerCase();
        const valueB = String(b[sortBy] ?? "").toLowerCase();

        if (sortBy === "status") {
          return direction === "asc"
            ? Number(a.status || 0) - Number(b.status || 0)
            : Number(b.status || 0) - Number(a.status || 0);
        }

        if (["cartValue", "productCount"].includes(sortBy)) {
          return direction === "asc"
            ? Number(a[sortBy] || 0) - Number(b[sortBy] || 0)
            : Number(b[sortBy] || 0) - Number(a[sortBy] || 0);
        }

        if (sortBy === "lastActivityAt") {
          const timeA = new Date(a.lastActivityAt || "").getTime() || 0;
          const timeB = new Date(b.lastActivityAt || "").getTime() || 0;

          return direction === "asc" ? timeA - timeB : timeB - timeA;
        }

        return direction === "asc"
          ? valueA.localeCompare(valueB)
          : valueB.localeCompare(valueA);
      });

      return items;
    },

    onDetailSortChange(findingId, event) {
      const column =
        event?.column?.property ||
        event?.property ||
        event?.dataIndex ||
        event?.sortBy ||
        event?.columnName ||
        event;

      if (!column) {
        return;
      }

      this.toggleDetailSort(findingId, column);
    },
  },
});
