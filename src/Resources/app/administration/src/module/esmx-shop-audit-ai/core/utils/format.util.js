import { SEO_REASON_MAP } from '../constants/seo-reason.constant';

export function getSeoReasonLabel(tc, input) {
    const reason = typeof input === 'string'
        ? input
        : input?.reason || input?.issue || '';

    if (!reason) {
        return '-';
    }

    const key = SEO_REASON_MAP[reason];

    if (!key) {
        return reason;
    }

    return getTranslatedFallback(tc, key, reason);
}
export function formatDateTime(value, locale = undefined) {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

export function formatLatestScanDate(scan, locale = undefined) {
    if (!scan) {
        return null;
    }

    return formatDateTime(scan.finishedAt || scan.startedAt || null, locale);
}

export function getTranslatedFallback(tc, key, fallback = '-') {
    const translated = tc(key);

    return translated !== key ? translated : fallback;
}

export function getFindingTitleByCode(tc, code, fallbackTitle = '') {
    if (!code) {
        return fallbackTitle || '-';
    }

    return getTranslatedFallback(
        tc,
        `esmx-shop-audit-ai.findingTitles.${code}`,
        fallbackTitle || code
    );
}

export function getDynamicTaskTitle(tc, task) {
    if (!task) {
        return '';
    }

    const count = task.affectedCount || 0;
    const key = `esmx-shop-audit-ai.taskTitles.${task.code}`;
    const translated = tc(key, count, { count });

    if (translated !== key) {
        return translated;
    }

    return task.title || task.code || '-';
}

export function getPriorityLabel(tc, priority) {
    if (!priority) {
        return '-';
    }

    return getTranslatedFallback(
        tc,
        `esmx-shop-audit-ai.taskPriority.${priority}`,
        priority
    );
}

export function getStatusLabel(tc, status) {
    if (!status) {
        return '-';
    }

    return getTranslatedFallback(
        tc,
        `esmx-shop-audit-ai.status.${status}`,
        status
    );
}

export function getSeverityLabel(tc, severity) {
    if (!severity) {
        return '-';
    }

    const normalized = String(severity).toLowerCase().trim();

    return getTranslatedFallback(
        tc,
        `esmx-shop-audit-ai.severity.${normalized}`,
        normalized
    );
}

export function formatCurrency(value, locale = undefined) {
    const numericValue = Number(value ?? 0);

    return numericValue.toLocaleString(locale, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function formatPercent(value, fractionDigits = 2) {
    return `${Number(value || 0).toFixed(fractionDigits)}%`;
}

export function formatAdminDateTime(value) {
    if (!value) {
        return '';
    }

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
}