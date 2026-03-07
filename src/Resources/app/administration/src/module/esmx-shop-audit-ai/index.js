import './page/esmx-shop-audit-dashboard';
import './page/esmx-shop-audit-findings';
import './page/esmx-shop-audit-tasks';
import './page/esmx-shop-audit-reports';
import './page/esmx-shop-audit-settings';

Shopware.Module.register('esmx-shop-audit-ai', {
    type: 'plugin',
    name: 'esmx-shop-audit-ai',
    title: 'esmx-shop-audit-ai.general.mainMenuItemGeneral',
    description: 'esmx-shop-audit-ai.general.descriptionTextModule',
    color: '#9AA8B5',
    icon: 'regular-chart-bar',

    routes: {
        index: {
            component: 'esmx-shop-audit-dashboard',
            path: 'dashboard'
        },
        findings: {
            component: 'esmx-shop-audit-findings',
            path: 'findings'
        },
        tasks: {
            component: 'esmx-shop-audit-tasks',
            path: 'tasks'
        },
        reports: {
            component: 'esmx-shop-audit-reports',
            path: 'reports'
        },
        settings: {
            component: 'esmx-shop-audit-settings',
            path: 'settings'
        }
    },

    navigation: [
        {
            id: 'esmx-shop-audit-ai',
            label: 'esmx-shop-audit-ai.general.mainMenuItemGeneral',
            color: '#9AA8B5',
            path: 'esmx.shop.audit.ai.index',
            icon: 'regular-chart-bar',
            parent: 'sw-marketing',
            position: 110
        }
    ]
});