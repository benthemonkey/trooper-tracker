/**
 * Parse a US-style date string: "m/d/Y"
 * Returns a JS Date or null.
 */
export function parseUsDate(dateStr) {
    if (!dateStr) {
        return null;
    }

    const [m, d, y] = dateStr.split('/').map(Number);

    if (!m || !d || !y) {
        return null;
    }

    // JS months are 0-based
    return new Date(y, m - 1, d, 0, 0, 0, 0);
}

// Parse "m/d/Y - Hi" or "Y-m-d - H:i" → JS Date
export function parseUsDateTime(dateTimeStr) {
    if (!dateTimeStr) {
        return null;
    }

    // Time is "Hi" or "H:i" → e.g. "0930", "15:00"
    const us = dateTimeStr.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s*-\s*(\d{1,2}):?(\d{2})$/);
    const iso = dateTimeStr.match(/^(\d{4})-(\d{1,2})-(\d{1,2})\s*-\s*(\d{1,2}):?(\d{2})$/);

    let y, m, d, hour, minute;

    if (us) {
        [m, d, y, hour, minute] = us.slice(1).map(Number);
    }
    else if (iso) {
        [y, m, d, hour, minute] = iso.slice(1).map(Number);
    }
    else {
        return null;
    }

    if (!m || !d || !y || hour > 23 || minute > 59) {
        return null;
    }

    return new Date(y, m - 1, d, hour, minute, 0, 0);
}

export function formatUsDateTime(date) {
    if (!(date instanceof Date) || isNaN(date)) {
        return null;
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    let hour = date.getHours();

    const minute = String(date.getMinutes()).padStart(2, '0');
    const ampm = hour >= 12 ? 'PM' : 'AM';
    hour = hour % 12;

    if (hour === 0) {
        hour = 12;
    }

    return `${year}-${month}-${day} ${hour}:${minute}${ampm}`;
}

/**
 * Parse a 24-hour time string: "H:i"
 * Returns { hour, minute } or null.
 */
export function parseTime(timeStr) {
    if (!timeStr) {
        return null;
    }

    const [h, m] = timeStr.split(':').map(Number);

    if (Number.isNaN(h) || Number.isNaN(m)) {
        return null;
    }

    return { hour: h, minute: m };
}

/**
 * Convert a numeric-like string to an integer or null.
 */
export function toInt(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}
