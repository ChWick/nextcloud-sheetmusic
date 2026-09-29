import { translate as t } from '@nextcloud/l10n'

/**
 * Turn a failed Axios request into a localized message. A 404/405 is
 * surfaced explicitly because it usually means the server has not loaded
 * updated PHP files yet.
 */
export function describeRequestFailure(error: unknown, fallback: string): string {
	const status = (error as { response?: { status?: number } })?.response?.status
	if (status === 404 || status === 405) {
		return t(
			'mobilesheetsmanager',
			'The server does not know this endpoint (HTTP {status}). If the app code was just changed, restart Nextcloud so it loads the new PHP files.',
			{ status: String(status) },
		)
	}
	return fallback
}
