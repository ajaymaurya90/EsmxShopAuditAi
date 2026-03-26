export function goToDashboard(router) {
    return router.push({ name: 'esmx.shop.audit.ai.index' });
}

export function goToFindings(router, query = {}) {
    return router.push({
        name: 'esmx.shop.audit.ai.findings',
        query,
    });
}

export function goToTasks(router, query = {}) {
    return router.push({
        name: 'esmx.shop.audit.ai.tasks',
        query,
    });
}

export function goToReports(router) {
    return router.push({ name: 'esmx.shop.audit.ai.reports' });
}

export function goToSettings(router) {
    return router.push({ name: 'esmx.shop.audit.ai.settings' });
}
