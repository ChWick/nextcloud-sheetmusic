import { translate as t } from '@nextcloud/l10n'

/**
 * Turn a failed Axios request into a localized message. A 404/405 is
 * surfaced explicitly because it usually means the server has not loaded
 * updated PHP files yet.
 */
export function describeRequestFailure(error: unknown, fallback: string): string {
	const response = record(record(error)?.response)
	const status = response?.status
	if (status === 404 || status === 405) {
		return t(
			'sheetmusic',
			'The server does not know this endpoint (HTTP {status}). If the app code was just changed, restart Nextcloud so it loads the new PHP files.',
			{ status: String(status) }, { escape: false },
		)
	}

	const responseData = record(response?.data)
	const ocs = record(responseData?.ocs)
	const ocsData = record(ocs?.data)
	const ocsMeta = record(ocs?.meta)
	const message = ocsData?.message ?? ocsData?.error ?? ocsMeta?.message ?? responseData?.message ?? responseData?.error
	if (typeof message === 'string' && message !== '') {
		return message
	}

	return fallback
}

function record(value: unknown): Record<string, unknown> | null {
	return typeof value === 'object' && value !== null && !Array.isArray(value)
		? value as Record<string, unknown>
		: null
}
