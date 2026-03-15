
import template from './esmx-shop-audit-tasks.html.twig';
import '../../shared/esmx-shop-audit-shared.scss'

Shopware.Component.register('esmx-shop-audit-tasks', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            latestScan: null,
            tasks: [],
            loadError: null,
            scanError: null,
            activeWidgetTooltip: null,
            sortBy: 'priority',
            sortDirection: 'DESC',
            isPriorityFilterMenuOpen: false,
            isStatusFilterMenuOpen: false,
            selectedPriorityFilters: [],
            selectedStatusFilters: [],
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.tasks.pageTitle');
        },

        activePriorityFilter() {
            return this.$route.query.priority || null;
        },

        activeCodeFilter() {
            return this.$route.query.code || null;
        },

        formattedLatestScanDate() {
            if (!this.latestScan?.finishedAt && !this.latestScan?.startedAt) {
                return null;
            }

            const value = this.latestScan.finishedAt || this.latestScan.startedAt;

            try {
                return Shopware.Utils.format.date(value, {
                    hour: '2-digit',
                    minute: '2-digit',
                    year: 'numeric',
                    month: 'short',
                    day: '2-digit',
                });
            } catch (e) {
                return value;
            }
        },

        priorityFilters() {
            const allKeys = ['high', 'medium', 'low'];

            return allKeys.map((key) => ({
                key,
                label: key,
                count: this.tasks.filter((task) => task.priority === key).length,
                disabled: this.tasks.filter((task) => task.priority === key).length === 0,
            }));
        },

        statusFilters() {
            const statusKeys = [...new Set(this.tasks.map((task) => task.status).filter(Boolean))];

            return statusKeys.map((key) => ({
                key,
                label: key,
                count: this.tasks.filter((task) => task.status === key).length,
                disabled: this.tasks.filter((task) => task.status === key).length === 0,
            }));
        },

        processedTasks() {
            const filtered = this.tasks.filter((item) => {
                const routePriorityMatch = !this.activePriorityFilter || item.priority === this.activePriorityFilter;
                const routeCodeMatch = !this.activeCodeFilter || item.code === this.activeCodeFilter;

                const priorityMatch = !this.selectedPriorityFilters.length
                    || this.selectedPriorityFilters.includes(item.priority);

                const statusMatch = !this.selectedStatusFilters.length
                    || this.selectedStatusFilters.includes(item.status);

                return routePriorityMatch && routeCodeMatch && priorityMatch && statusMatch;
            });

            return [...filtered].sort((a, b) => this.sortTasks(a, b));
        },

        totalTasks() {
            return this.tasks.length;
        },

        openTaskCount() {
            return this.tasks.filter((task) => task.status === 'open').length;
        },

        highPriorityTaskCount() {
            return this.tasks.filter((task) => task.priority === 'high').length;
        },

        mediumPriorityTaskCount() {
            return this.tasks.filter((task) => task.priority === 'medium').length;
        },

        resolvedTaskCount() {
            return this.tasks.filter((task) => ['done', 'resolved'].includes(task.status)).length;
        },

        totalAffectedCount() {
            return this.tasks.reduce((sum, task) => sum + (task.affectedCount || 0), 0);
        },

        hasActiveInlineFilters() {
            return !!(
                this.selectedPriorityFilters.length
                || this.selectedStatusFilters.length
                || this.activePriorityFilter
                || this.activeCodeFilter
            );
        },

        columns() {
            return [
                {
                    property: 'title',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.title'),
                    primary: true,
                    sortable: true,
                },
                {
                    property: 'priority',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.priority'),
                    sortable: true,
                },
                {
                    property: 'affectedCount',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.affectedCount'),
                    align: 'right',
                    sortable: true,
                },
                {
                    property: 'status',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.status'),
                    sortable: true,
                }
            ];
        }
    },

    created() {
        this.loadPageData();
    },

    methods: {
        loadPageData() {
            this.isLoading = true;
            this.loadError = null;

            this.esmxShopAuditApiService.getLatestTasks()
                .then((response) => {
                    this.latestScan = response.scan;
                    this.tasks = response.tasks ?? [];
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi tasks error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.tasks.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        runScan() {
            this.isRunningScan = true;
            this.scanError = null;

            this.esmxShopAuditApiService.runScan()
                .then(() => this.loadPageData())
                .catch((error) => {
                    console.error('EsmxShopAuditAi tasks run scan error:', error);
                    this.scanError = this.$tc('esmx-shop-audit-ai.dashboard.runScanError');
                })
                .finally(() => {
                    this.isRunningScan = false;
                });
        },

        clearFilters() {
            this.selectedPriorityFilters = [];
            this.selectedStatusFilters = [];
            this.isPriorityFilterMenuOpen = false;
            this.isStatusFilterMenuOpen = false;
            this.$router.push({ name: 'esmx.shop.audit.ai.tasks' });
        },

        goToDashboard() {
            this.$router.push({ name: 'esmx.shop.audit.ai.index' });
        },

        goToFindings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.findings' });
        },

        goToReports() {
            this.$router.push({ name: 'esmx.shop.audit.ai.reports' });
        },

        goToSettings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.settings' });
        },

        getPriorityVariant(priority) {
            switch (priority) {
                case 'high':
                    return 'danger';
                case 'medium':
                    return 'warning';
                case 'low':
                    return 'info';
                default:
                    return 'neutral';
            }
        },

        getStatusVariant(status) {
            switch (status) {
                case 'open':
                    return 'warning';
                case 'done':
                case 'resolved':
                    return 'success';
                case 'in_progress':
                    return 'info';
                default:
                    return 'neutral';
            }
        },

        onSortColumn(column) {
            if (!column?.property) {
                return;
            }

            if (this.sortBy === column.property) {
                this.sortDirection = this.sortDirection === 'ASC' ? 'DESC' : 'ASC';
                return;
            }

            this.sortBy = column.property;
            this.sortDirection = column.property === 'priority' ? 'DESC' : 'ASC';
        },

        sortTasks(a, b) {
            const direction = this.sortDirection === 'ASC' ? 1 : -1;

            if (this.sortBy === 'priority') {
                const priorityWeight = {
                    high: 3,
                    medium: 2,
                    low: 1,
                };

                const aValue = priorityWeight[a.priority] || 0;
                const bValue = priorityWeight[b.priority] || 0;

                if (aValue !== bValue) {
                    return (aValue - bValue) * direction;
                }

                return ((a.affectedCount || 0) - (b.affectedCount || 0)) * direction;
            }

            if (this.sortBy === 'affectedCount') {
                return ((a.affectedCount || 0) - (b.affectedCount || 0)) * direction;
            }

            const aValue = String(a[this.sortBy] || '').toLowerCase();
            const bValue = String(b[this.sortBy] || '').toLowerCase();

            if (aValue === bValue) {
                return 0;
            }

            return aValue > bValue ? direction : -direction;
        },

        togglePriorityFilterMenu() {
            this.isPriorityFilterMenuOpen = !this.isPriorityFilterMenuOpen;
            this.isStatusFilterMenuOpen = false;
        },

        toggleStatusFilterMenu() {
            this.isStatusFilterMenuOpen = !this.isStatusFilterMenuOpen;
            this.isPriorityFilterMenuOpen = false;
        },

        isPrioritySelected(key) {
            return this.selectedPriorityFilters.includes(key);
        },

        isStatusSelected(key) {
            return this.selectedStatusFilters.includes(key);
        },

        togglePriorityFilter(key) {
            if (this.selectedPriorityFilters.includes(key)) {
                this.selectedPriorityFilters = this.selectedPriorityFilters.filter((item) => item !== key);
                return;
            }

            this.selectedPriorityFilters = [...this.selectedPriorityFilters, key];
        },

        toggleStatusFilter(key) {
            if (this.selectedStatusFilters.includes(key)) {
                this.selectedStatusFilters = this.selectedStatusFilters.filter((item) => item !== key);
                return;
            }

            this.selectedStatusFilters = [...this.selectedStatusFilters, key];
        }
    }
});