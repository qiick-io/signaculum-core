/**
 * "5 minutes ago" for a Unix timestamp (seconds), in the active UI language.
 * Uses the platform's Intl.RelativeTimeFormat, so no date library is needed.
 */
export function formatRelativeTime(
    unixSeconds: number,
    locale: string,
): string {
    const elapsedSeconds = Math.round(unixSeconds - Date.now() / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 60 * 60 * 24 * 365],
        ['month', 60 * 60 * 24 * 30],
        ['day', 60 * 60 * 24],
        ['hour', 60 * 60],
        ['minute', 60],
    ];
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, secondsPerUnit] of units) {
        if (Math.abs(elapsedSeconds) >= secondsPerUnit) {
            return formatter.format(
                Math.round(elapsedSeconds / secondsPerUnit),
                unit,
            );
        }
    }

    return formatter.format(0, 'minute');
}
