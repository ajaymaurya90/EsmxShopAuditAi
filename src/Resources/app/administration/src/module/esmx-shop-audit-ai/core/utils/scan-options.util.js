import {
    DEFAULT_SCAN_OPTIONS,
    SCAN_OPTIONS_STORAGE_KEY,
} from '../constants/scan-options.constant';

function cloneOptions(options) {
    return JSON.parse(JSON.stringify(options));
}

function hasEnabledChecks(checks = {}) {
    return Object.values(checks).some((enabled) => enabled === true);
}

export function normalizeScanOptions(options = null) {
    const normalized = cloneOptions(DEFAULT_SCAN_OPTIONS);

    if (!options || typeof options !== 'object') {
        return normalized;
    }

    Object.keys(normalized).forEach((groupKey) => {
        if (groupKey === 'version') {
            return;
        }

        const incomingGroup = options[groupKey];

        if (!incomingGroup || typeof incomingGroup !== 'object') {
            return;
        }

        if (typeof incomingGroup.enabled === 'boolean') {
            normalized[groupKey].enabled = incomingGroup.enabled;
        }

        const incomingChecks = incomingGroup.checks;

        if (incomingChecks && typeof incomingChecks === 'object') {
            Object.keys(normalized[groupKey].checks).forEach((checkKey) => {
                if (typeof incomingChecks[checkKey] === 'boolean') {
                    normalized[groupKey].checks[checkKey] = incomingChecks[checkKey];
                }
            });
        }

        normalized[groupKey].enabled = hasEnabledChecks(normalized[groupKey].checks);
    });

    return normalized;
}

export function loadScanOptions() {
    try {
        const stored = window.localStorage.getItem(SCAN_OPTIONS_STORAGE_KEY);

        if (!stored) {
            return cloneOptions(DEFAULT_SCAN_OPTIONS);
        }

        return normalizeScanOptions(JSON.parse(stored));
    } catch (error) {
        return cloneOptions(DEFAULT_SCAN_OPTIONS);
    }
}

export function saveScanOptions(options) {
    const normalized = normalizeScanOptions(options);

    try {
        window.localStorage.setItem(
            SCAN_OPTIONS_STORAGE_KEY,
            JSON.stringify(normalized)
        );
    } catch (error) {
        // Ignore storage failures; the normalized options can still be sent with the request.
    }

    return normalized;
}

export function resetScanOptions() {
    const defaults = cloneOptions(DEFAULT_SCAN_OPTIONS);

    try {
        window.localStorage.removeItem(SCAN_OPTIONS_STORAGE_KEY);
    } catch (error) {
        // Ignore storage failures and return defaults.
    }

    return defaults;
}
