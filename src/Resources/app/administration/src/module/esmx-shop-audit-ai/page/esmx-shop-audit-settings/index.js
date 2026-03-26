import template from './esmx-shop-audit-settings.html.twig';
import './esmx-shop-audit-settings.scss'

const { Criteria } = Shopware.Data;

Shopware.Component.register('esmx-shop-audit-settings', {
    template,

    inject: ['systemConfigApiService'],

    mixins: [
        Shopware.Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isSaving: false,
            loadError: null,
            configDomain: 'EsmxShopAuditAi.config',
            config: {
                enableAudit: true,
                auditProductLimit: 100,
                variantAuditMode: 'effective',
                checkMissingManufacturer: true,
                checkMissingTranslations: true,
                checkSeoFields: true,
                checkProductMetaDescription: true,
                checkWeakProductTitle: true,
                minProductTitleLength: 20,
                checkShortProductDescription: true,
                minProductDescriptionLength: 80,
                checkCategorySeo: true,
            },
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.settings.pageTitle');
        },

        variantAuditModeOptions() {
            return [
                {
                    value: 'effective',
                    label: this.$tc('esmx-shop-audit-ai.settings.fields.variantAuditMode.options.effective'),
                },
                {
                    value: 'raw',
                    label: this.$tc('esmx-shop-audit-ai.settings.fields.variantAuditMode.options.raw'),
                },
            ];
        },
    },

    created() {
        this.loadSettings();
    },

    methods: {
        loadSettings() {
            this.isLoading = true;
            this.loadError = null;

            this.systemConfigApiService.getValues(this.configDomain)
                .then((values) => {
                    this.config.enableAudit = this.getBoolConfig(values, 'enableAudit', true);
                    this.config.auditProductLimit = this.getIntConfig(values, 'auditProductLimit', 100);
                    this.config.variantAuditMode = this.getStringConfig(values, 'variantAuditMode', 'effective');

                    this.config.checkMissingManufacturer = this.getBoolConfig(values, 'checkMissingManufacturer', true);
                    this.config.checkMissingTranslations = this.getBoolConfig(values, 'checkMissingTranslations', true);
                    this.config.checkSeoFields = this.getBoolConfig(values, 'checkSeoFields', true);
                    this.config.checkProductMetaDescription = this.getBoolConfig(values, 'checkProductMetaDescription', true);
                    this.config.checkWeakProductTitle = this.getBoolConfig(values, 'checkWeakProductTitle', true);
                    this.config.minProductTitleLength = this.getIntConfig(values, 'minProductTitleLength', 20);
                    this.config.checkShortProductDescription = this.getBoolConfig(values, 'checkShortProductDescription', true);
                    this.config.minProductDescriptionLength = this.getIntConfig(values, 'minProductDescriptionLength', 80);
                    this.config.checkCategorySeo = this.getBoolConfig(values, 'checkCategorySeo', true);
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi settings load error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.settings.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        saveSettings() {
            this.isSaving = true;

            const payload = {
                [`${this.configDomain}.enableAudit`]: !!this.config.enableAudit,
                [`${this.configDomain}.auditProductLimit`]: Number(this.config.auditProductLimit) || 100,
                [`${this.configDomain}.variantAuditMode`]: this.config.variantAuditMode || 'effective',

                [`${this.configDomain}.checkMissingManufacturer`]: !!this.config.checkMissingManufacturer,
                [`${this.configDomain}.checkMissingTranslations`]: !!this.config.checkMissingTranslations,
                [`${this.configDomain}.checkSeoFields`]: !!this.config.checkSeoFields,
                [`${this.configDomain}.checkProductMetaDescription`]: !!this.config.checkProductMetaDescription,
                [`${this.configDomain}.checkWeakProductTitle`]: !!this.config.checkWeakProductTitle,
                [`${this.configDomain}.minProductTitleLength`]: Number(this.config.minProductTitleLength) || 20,
                [`${this.configDomain}.checkShortProductDescription`]: !!this.config.checkShortProductDescription,
                [`${this.configDomain}.minProductDescriptionLength`]: Number(this.config.minProductDescriptionLength) || 80,
                [`${this.configDomain}.checkCategorySeo`]: !!this.config.checkCategorySeo,
            };

            this.systemConfigApiService.saveValues(payload)
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('esmx-shop-audit-ai.settings.saveSuccess'),
                    });

                    return this.loadSettings();
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi settings save error:', error);
                    this.createNotificationError({
                        message: this.$tc('esmx-shop-audit-ai.settings.saveError'),
                    });
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },

        getBoolConfig(values, key, fallback = false) {
            const fullKey = `${this.configDomain}.${key}`;

            if (typeof values?.[fullKey] === 'boolean') {
                return values[fullKey];
            }

            return fallback;
        },

        getIntConfig(values, key, fallback = 0) {
            const fullKey = `${this.configDomain}.${key}`;
            const value = Number(values?.[fullKey]);

            return Number.isNaN(value) ? fallback : value;
        },

        getStringConfig(values, key, fallback = '') {
            const fullKey = `${this.configDomain}.${key}`;
            const value = values?.[fullKey];

            return typeof value === 'string' && value !== '' ? value : fallback;
        },

        goToDashboard() {
            this.$router.push({ name: 'esmx.shop.audit.ai.index' });
        },

        goToFindings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.findings' });
        },

        goToTasks() {
            this.$router.push({ name: 'esmx.shop.audit.ai.tasks' });
        },

        goToReports() {
            this.$router.push({ name: 'esmx.shop.audit.ai.reports' });
        }
    }
});