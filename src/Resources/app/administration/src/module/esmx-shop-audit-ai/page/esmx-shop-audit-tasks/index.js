import template from './esmx-shop-audit-tasks.html.twig';
import '../../shared/esmx-shop-audit-shared.scss';
import './esmx-shop-audit-tasks.scss';
import {
    formatLatestScanDate,
    formatAdminDateTime,
    formatCurrency,
    getDynamicTaskTitle,
    getPriorityLabel,
    getStatusLabel,
    getSeoReasonLabel,
} from '../../core/utils/format.util';
import {
    goToDashboard,
    goToFindings,
    goToReports,
} from '../../core/utils/navigation.util';
import { loadScanOptions } from '../../core/utils/scan-options.util';

Shopware.Component.register('esmx-shop-audit-tasks', {
    template,

    inject: ['esmxShopAuditApiService'],

    mixins: [
        Shopware.Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            latestScan: null,
            tasks: [],
            loadError: null,
            scanError: null,
            activeWidgetTooltip: null,
            sortBy: 'impactScore',
            sortDirection: 'DESC',
            isPriorityFilterMenuOpen: false,
            isStatusFilterMenuOpen: false,
            selectedPriorityFilters: [],
            selectedStatusFilters: [],

            selectedTaskId: null,
            isTaskDetailLoading: false,
            selectedTaskDetails: null,
            activeDetailActionMenuId: null,

            isAutoFixModalOpen: false,
            isAutoFixPreviewLoading: false,
            isApplyingAutoFix: false,
            autoFixPreview: null,
            autoFixTargetItem: null,
            autoFixError: null,
            autoFixAiError: null,
            isGeneratingAiSuggestion: false,
            editableSuggestedValue: '',
            aiSuggestionMeta: null,

            isAutoFixAllModalOpen: false,
            autoFixAllError: null,

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
            return formatLatestScanDate(this.latestScan);
        },

        priorityFilters() {
            const allKeys = ['high', 'medium', 'low'];

            return allKeys.map((key) => ({
                key,
                label: this.getPriorityLabel(key),
                count: this.tasks.filter((task) => task.priority === key).length,
                disabled: this.tasks.filter((task) => task.priority === key).length === 0,
            }));
        },

        statusFilters() {
            const statusKeys = [...new Set(this.tasks.map((task) => task.status).filter(Boolean))];

            return statusKeys.map((key) => ({
                key,
                label: this.getStatusLabel(key),
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

        selectedTask() {
            if (!this.selectedTaskId) {
                return null;
            }

            return this.tasks.find((task) => task.id === this.selectedTaskId) || null;
        },

        detailItems() {
            const items = this.selectedTaskDetails?.items || [];

            if (!this.isBrokenLinkTask) {
                return items;
            }

            return items.map((item, index) => this.normalizeBrokenLinkTaskItem(item, index));
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
                    property: 'impactScore',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.impact'),
                    align: 'right',
                    sortable: true,
                },
                {
                    property: 'status',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.status'),
                    sortable: true,
                }
            ];
        },

        sortIconMap() {
            return {
                ASC: 'regular-chevron-up-s',
                DESC: 'regular-chevron-down-s',
            };
        },

        seoTaskCodes() {
            return [
                'review_product_names',
                'review_product_descriptions',
                'review_product_meta_titles',
                'review_product_meta_descriptions',
            ];
        },

        isSeoFieldTask() {
            return !!this.selectedTask && this.seoTaskCodes.includes(this.selectedTask.code);
        },

        isBrokenLinkTask() {
            const task = this.selectedTaskDetails?.task || this.selectedTask;
            const findingCode = task?.payloadJson?.findingCode;

            return task?.code === 'fix_broken_links' || findingCode === 'broken_links';
        },

        isCustomerAuditTask() {
            const task = this.selectedTaskDetails?.task || this.selectedTask;
            const findingCode = task?.payloadJson?.findingCode;

            return task?.code === 'recover_abandoned_carts' || findingCode === 'abandoned_cart_customers';
        },

        detailColumns() {
            if (this.isCustomerAuditTask) {
                return [
                    {
                        property: 'name',
                        label: this.$tc('esmx-shop-audit-ai.customerAudit.columns.customer'),
                        primary: true,
                        width: '22%',
                    },
                    {
                        property: 'email',
                        label: this.$tc('esmx-shop-audit-ai.customerAudit.columns.email'),
                        width: '20%',
                    },
                    {
                        property: 'cartValue',
                        label: this.$tc('esmx-shop-audit-ai.customerAudit.columns.cartValue'),
                        width: '12%',
                    },
                    {
                        property: 'productCount',
                        label: this.$tc('esmx-shop-audit-ai.customerAudit.columns.productCount'),
                        width: '10%',
                    },
                    {
                        property: 'lastActivityAt',
                        label: this.$tc('esmx-shop-audit-ai.customerAudit.columns.lastActivity'),
                        width: '18%',
                    },
                    {
                        property: 'salesChannelName',
                        label: this.$tc('esmx-shop-audit-ai.customerAudit.columns.salesChannel'),
                        width: '14%',
                    },
                    {
                        property: 'actions',
                        label: '',
                        width: '72px',
                        align: 'center',
                    },
                ];
            }

            if (this.isBrokenLinkTask) {
                return [
                    {
                        property: 'entity',
                        label: this.$tc('esmx-shop-audit-ai.findings.columns.entity'),
                        primary: true,
                        width: '12%',
                    },
                    {
                        property: 'name',
                        label: this.$tc('esmx-shop-audit-ai.findings.columns.name'),
                        width: '20%',
                    },
                    {
                        property: 'languageName',
                        label: this.$tc('esmx-shop-audit-ai.findings.columns.language'),
                        width: '12%',
                    },
                    {
                        property: 'source',
                        label: this.$tc('esmx-shop-audit-ai.findings.columns.source'),
                        width: '16%',
                    },
                    {
                        property: 'status',
                        label: this.$tc('esmx-shop-audit-ai.findings.columns.status'),
                        width: '10%',
                    },
                    {
                        property: 'url',
                        label: this.$tc('esmx-shop-audit-ai.findings.columns.url'),
                        width: '24%',
                    },
                    {
                        property: 'actions',
                        label: '',
                        width: '72px',
                        align: 'center',
                    },
                ];
            }

            if (this.isSeoFieldTask) {
                return [
                    {
                        property: 'name',
                        label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.name'),
                        primary: true,
                        width: '28%',
                    },
                    {
                        property: 'identifier',
                        label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.identifier'),
                        width: '16%',
                    },
                    {
                        property: 'seoScore',
                        label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.seoScore'),
                        align: 'right',
                        width: '10%',
                    },
                    {
                        property: 'severity',
                        label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.severity'),
                        width: '16%',
                    },
                    {
                        property: 'reason',
                        label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.reason'),
                        width: '24%',
                    },
                    {
                        property: 'actions',
                        label: '',
                        width: '72px',
                        align: 'center',
                    },
                ];
            }

            return [
                {
                    property: 'name',
                    label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.name'),
                    primary: true,
                    width: '40%',
                },
                {
                    property: 'identifier',
                    label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.identifier'),
                    width: '20%',
                },
                {
                    property: 'currentValue',
                    label: this.$tc('esmx-shop-audit-ai.tasks.detailGrid.columns.currentValue'),
                    width: '30%',
                },
                {
                    property: 'actions',
                    label: '',
                    width: '72px',
                    align: 'center',
                },
            ];
        },

        canApplyAutoFixAll() {
            return !!this.selectedTask
                && !!this.detailItems.length
                && this.detailItems.some((item) => item.autoFixSupported);
        },

        canGenerateAiSuggestion() {
            return !!this.selectedTaskId
                && !!this.autoFixTargetItem?.id
                && !!this.autoFixPreview
                && this.isSeoFieldTask
                && this.autoFixPreview.supported !== false
                && !this.isAutoFixPreviewLoading
                && !this.isApplyingAutoFix
                && !this.isGeneratingAiSuggestion
                && !this.isRunningScan;
        },

    },

    created() {
        this.loadPageData();
    },

    methods: {

        loadPageData() {
            this.isLoading = true;
            this.loadError = null;

            return this.esmxShopAuditApiService.getLatestTasks()
                .then((response) => {
                    this.latestScan = response.scan;
                    this.tasks = response.tasks ?? [];

                    if (this.selectedTaskId) {
                        const stillExists = this.tasks.some((task) => task.id === this.selectedTaskId);

                        if (!stillExists) {
                            this.selectedTaskId = null;
                            this.selectedTaskDetails = null;
                        }
                    }
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

            this.esmxShopAuditApiService.runScan(loadScanOptions())
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
            return goToDashboard(this.$router);
        },

        openScanOptionsModal() {
            return goToDashboard(this.$router, { scanOptions: '1' });
        },

        goToFindings() {
            return goToFindings(this.$router);
        },

        goToReports() {
            return goToReports(this.$router);
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

        getDynamicTaskTitle(task) {
            return getDynamicTaskTitle(this.$tc.bind(this), task);
        },

        getPriorityLabel(priority) {
            return getPriorityLabel(this.$tc.bind(this), priority);
        },

        getStatusLabel(status) {
            return getStatusLabel(this.$tc.bind(this), status);
        },

        getCategoryLabel(entity) {
            if (!entity) {
                return this.$tc('esmx-shop-audit-ai.findings.unknownCategory');
            }

            const normalized = String(entity).toLowerCase();
            const labels = {
                product: this.$tc('esmx-shop-audit-ai.findings.categoryProduct'),
                category: this.$tc('esmx-shop-audit-ai.findings.categoryCategory'),
                cms_page: this.$tc('esmx-shop-audit-ai.findings.categoryCmsPage'),
                link: this.$tc('esmx-shop-audit-ai.findings.categoryBrokenLink'),
                customer: this.$tc('esmx-shop-audit-ai.findings.categoryCustomer'),
                order: this.$tc('esmx-shop-audit-ai.findings.categoryOrder'),
            };

            return labels[normalized] || entity;
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

            if (this.sortBy === 'impactScore') {
                return ((a.impactScore || 0) - (b.impactScore || 0)) * direction;
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
        },

        async onTaskClick(task) {
            if (!task?.id) {
                return;
            }

            this.closeAllActionMenus();
            this.selectedTaskId = task.id;
            this.isTaskDetailLoading = true;
            this.selectedTaskDetails = null;

            try {
                const response = await this.esmxShopAuditApiService.getTaskDetail(task.id);
                this.selectedTaskDetails = response;
            } catch (error) {
                console.error('EsmxShopAuditAi task detail error:', error);
                this.selectedTaskDetails = { items: [] };
            } finally {
                this.isTaskDetailLoading = false;

                this.$nextTick(() => {
                    const sectionRef = this.$refs.taskDetailsSection;
                    const element = sectionRef?.$el || sectionRef;

                    if (element?.scrollIntoView) {
                        element.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start',
                        });
                    }
                });
            }
        },

        isTaskSelected(task) {
            return !!task?.id && task.id === this.selectedTaskId;
        },

        formatSeoScore(value) {
            if (value === null || value === undefined || value === '') {
                return '-';
            }

            const numericValue = Number(value);

            if (Number.isNaN(numericValue)) {
                return '-';
            }

            return Math.max(0, Math.min(100, Math.round(numericValue)));
        },

        getReasonLabel(item) {
            return getSeoReasonLabel(this.$tc.bind(this), item);
        },

        normalizeBrokenLinkTaskItem(item, index) {
            const sourceEntityId = item.sourceEntityId || item.entityId || item.raw?.id || item.id;
            const entity = item.entity || item.entityType || item.raw?.entity || 'link';

            return {
                ...item,
                id: item.id || `broken-link-${entity}-${sourceEntityId || index}-${index}`,
                sourceEntityId,
                entity,
                name: item.name || '-',
                languageName: item.languageName || '-',
                source: item.source || '-',
                status: item.status ?? '-',
                url: item.url || '-',
            };
        },

        formatMoney(value) {
            return formatCurrency(value);
        },

        formatDate(value) {
            return formatAdminDateTime(value);
        },

        openManualFix(item) {
            this.closeAllActionMenus();

            const entityId = item?.entityId || item?.id;
            const entityType = item?.entityType || 'product';

            if (!entityId) {
                console.warn('EsmxShopAuditAi manual fix: missing entity id', item);
                return;
            }

            let targetUrl = null;

            switch (entityType) {
                case 'product':
                    targetUrl = this.buildAdminUrl(`#/sw/product/detail/${entityId}`);
                    break;

                case 'category':
                    targetUrl = this.buildAdminUrl(`#/sw/category/index/${entityId}`);
                    break;

                default:
                    console.warn('EsmxShopAuditAi manual fix: unsupported entity type', entityType, item);
                    return;
            }

            window.open(targetUrl, '_blank', 'noopener');
        },

        buildAdminUrl(hashPath) {
            return `${window.location.origin}${window.location.pathname}${hashPath}`;
        },

        openBrokenLinkUrl(item) {
            this.closeAllActionMenus();

            if (!item?.url || item.url === '-') {
                return;
            }

            const url = item.url.startsWith('http')
                ? item.url
                : `${window.location.origin}${item.url}`;

            window.open(url, '_blank', 'noopener');
        },

        openAffectedEntityInNewTab(item) {
            this.closeAllActionMenus();

            if (item?.entity === 'product') {
                return this.openProductDetailInNewTab(item);
            }

            if (item?.entity === 'cms_page' && item?.sourceEntityId) {
                const resolved = this.$router.resolve({
                    name: 'sw.cms.detail',
                    params: {
                        id: item.sourceEntityId,
                    },
                });

                if (resolved?.href) {
                    window.open(resolved.href, '_blank', 'noopener');
                }
            }

            if (item?.entity === 'category' && item?.sourceEntityId) {
                const resolved = this.$router.resolve({
                    name: 'sw.category.detail',
                    params: {
                        id: item.sourceEntityId,
                    },
                });

                if (resolved?.href) {
                    window.open(resolved.href, '_blank', 'noopener');
                }
            }
        },

        openCustomerDetailInNewTab(item) {
            this.closeAllActionMenus();

            const customerId = item?.entityId || item?.customerId || item?.raw?.customerId;

            if (!customerId) {
                return;
            }

            const resolved = this.$router.resolve({
                name: 'sw.customer.detail',
                params: {
                    id: customerId,
                },
            });

            if (resolved?.href) {
                window.open(resolved.href, '_blank', 'noopener');
            }
        },

        openProductDetailInNewTab(item) {
            const productId = item?.productId || item?.sourceEntityId || item?.entityId || item?.id;

            if (!productId) {
                return;
            }

            const resolved = this.$router.resolve({
                name: 'sw.product.detail',
                params: {
                    id: productId,
                },
            });

            if (resolved?.href) {
                window.open(resolved.href, '_blank', 'noopener');
            }
        },

        async openAutoFix(item) {
            this.closeAllActionMenus();
            this.autoFixTargetItem = item;
            this.autoFixPreview = null;
            this.autoFixError = null;
            this.autoFixAiError = null;
            this.editableSuggestedValue = '';
            this.aiSuggestionMeta = null;
            this.isAutoFixPreviewLoading = true;
            this.isAutoFixModalOpen = true;

            try {
                const response = await this.esmxShopAuditApiService.getTaskAutoFixPreview(this.selectedTaskId, item.id);
                this.autoFixPreview = response;
                this.editableSuggestedValue = response?.suggestedValue || '';
            } catch (error) {
                console.error('EsmxShopAuditAi auto fix preview error:', error);
                this.autoFixError = this.$tc('esmx-shop-audit-ai.tasks.autoFix.previewError');
            } finally {
                this.isAutoFixPreviewLoading = false;
            }
        },

        async generateAiSuggestion() {
            if (!this.canGenerateAiSuggestion) {
                return;
            }

            this.isGeneratingAiSuggestion = true;
            this.autoFixAiError = null;
            this.aiSuggestionMeta = null;

            try {
                const response = await this.esmxShopAuditApiService.generateSeoSuggestion(
                    this.selectedTaskId,
                    this.autoFixTargetItem.id,
                    this.editableSuggestedValue
                );

                if (response?.success !== true || !response.suggestedValue) {
                    this.autoFixAiError = response?.message || this.$tc('esmx-shop-audit-ai.tasks.autoFix.aiSuggestionFailed');
                    return;
                }

                this.editableSuggestedValue = response.suggestedValue;
                this.aiSuggestionMeta = {
                    provider: response.provider || '',
                    model: response.model || '',
                };

                this.createNotificationSuccess({
                    message: this.$tc('esmx-shop-audit-ai.tasks.autoFix.aiSuggestionSuccess'),
                });
            } catch (error) {
                console.error('EsmxShopAuditAi AI SEO suggestion error:', error);
                this.autoFixAiError = this.$tc('esmx-shop-audit-ai.tasks.autoFix.aiSuggestionFailed');
            } finally {
                this.isGeneratingAiSuggestion = false;
            }
        },

        async applyAutoFix() {
            if (!this.selectedTaskId || !this.autoFixTargetItem?.id) {
                return;
            }

            this.isApplyingAutoFix = true;
            this.autoFixError = null;

            try {
                const result = await this.esmxShopAuditApiService.applyTaskAutoFix(
                    this.selectedTaskId,
                    this.autoFixTargetItem.id,
                    this.editableSuggestedValue
                );

                this.createNotificationSuccess({
                    message: result.taskCompleted
                        ? 'Task completed 🎉'
                        : this.$tc('esmx-shop-audit-ai.tasks.autoFix.success'),
                });

                await this.loadPageData();

                if (this.selectedTaskId) {
                    const response = await this.esmxShopAuditApiService.getTaskDetail(this.selectedTaskId);
                    this.selectedTaskDetails = response;
                }

                this.isApplyingAutoFix = false;
                this.closeAutoFixModal();
            } catch (error) {
                console.error('EsmxShopAuditAi auto fix apply error:', error);
                this.autoFixError = this.$tc('esmx-shop-audit-ai.tasks.autoFix.applyError');
                this.isApplyingAutoFix = false;
            }
        },

        openAutoFixAllModal() {
            if (!this.selectedTask || !this.detailItems.length) {
                return;
            }

            this.autoFixAllError = null;
            this.isAutoFixAllModalOpen = true;
        },

        closeAutoFixAllModal() {
            if (this.isApplyingAutoFix) {
                return;
            }

            this.isAutoFixAllModalOpen = false;
            this.autoFixAllError = null;
        },

        async confirmAutoFixAll() {
            if (!this.selectedTaskId) {
                return;
            }

            this.isApplyingAutoFix = true;
            this.autoFixAllError = null;

            try {
                const result = await this.esmxShopAuditApiService.applyTaskAutoFixAll(this.selectedTaskId);

                this.createNotificationSuccess({
                    message: this.$tc(
                        'esmx-shop-audit-ai.tasks.autoFixAll.success',
                        result.changed,
                        { count: result.changed }
                    ),
                });

                await this.loadPageData();

                if (this.selectedTaskId) {
                    const response = await this.esmxShopAuditApiService.getTaskDetail(this.selectedTaskId);
                    this.selectedTaskDetails = response;
                }

                this.isApplyingAutoFix = false;
                this.closeAutoFixAllModal();
            } catch (error) {
                console.error('Batch auto fix error:', error);
                this.autoFixAllError = this.$tc('esmx-shop-audit-ai.tasks.autoFixAll.applyError');
                this.isApplyingAutoFix = false;
            }
        },

        closeAutoFixModal() {
            if (this.isApplyingAutoFix) {
                return;
            }

            this.isAutoFixModalOpen = false;
            this.isAutoFixPreviewLoading = false;
            this.autoFixPreview = null;
            this.autoFixTargetItem = null;
            this.autoFixError = null;
            this.autoFixAiError = null;
            this.isGeneratingAiSuggestion = false;
            this.editableSuggestedValue = '';
            this.aiSuggestionMeta = null;
        },

        toggleDetailActionMenu(itemId) {
            this.activeDetailActionMenuId = this.activeDetailActionMenuId === itemId ? null : itemId;
        },

        closeAllActionMenus() {
            //this.activeTaskActionMenuId = null;
            this.activeDetailActionMenuId = null;
        },

        getSeoScoreClass(score) {
            const value = Number(score) || 0;

            if (value >= 80) return 'is-good';
            if (value >= 50) return 'is-average';
            return 'is-bad';
        },

        getSeoSeverity(score) {
            const value = Number(score) || 0;

            if (value >= 85) {
                return {
                    label: this.$tc('esmx-shop-audit-ai.seoSeverity.minorOpportunity'),
                    className: 'is-minor',
                };
            }

            if (value >= 70) {
                return {
                    label: this.$tc('esmx-shop-audit-ai.seoSeverity.optimization'),
                    className: 'is-optimization',
                };
            }

            if (value >= 40) {
                return {
                    label: this.$tc('esmx-shop-audit-ai.seoSeverity.needsImprovement'),
                    className: 'is-improvement',
                };
            }

            return {
                label: this.$tc('esmx-shop-audit-ai.seoSeverity.critical'),
                className: 'is-critical',
            };
        },

        getSeoSeverityLabel(score) {
            return this.getSeoSeverity(score).label;
        },

        getSeoSeverityClass(score) {
            return this.getSeoSeverity(score).className;
        },

        isEditableSuggestedValueChanged() {
            if (!this.autoFixPreview) {
                return false;
            }

            const currentValue = String(this.autoFixPreview.currentValue || '').trim();
            const suggestedValue = String(this.editableSuggestedValue || '').trim();

            return suggestedValue !== '' && suggestedValue !== currentValue;
        },

    },
});
