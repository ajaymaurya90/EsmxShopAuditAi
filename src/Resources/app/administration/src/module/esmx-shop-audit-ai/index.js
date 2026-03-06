import './page/esmx-shop-audit-dashboard';

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
            path: 'index'
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