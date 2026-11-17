export function getFileName(filePath: string): string {
	const clean = filePath.split('?')[0]
	const raw = clean.split('/').pop() ?? clean
	try {
		return decodeURIComponent(raw)
	} catch {
		return raw
	}
}

export function openFileExternally(filePath: string): void {
	window.open(filePath, '_blank', 'noopener,noreferrer')
}

/**
 * Appends a cache-busting timestamp query parameter to a file path or URL.
 */
export function getCacheBustedFileUrl(filePath: string, version?: number | string | null): string {
	if (!filePath) {
		return filePath
	}
	const v = version ?? Date.now()
	const separator = filePath.includes('?') ? '&' : '?'
	return `${filePath}${separator}t=${v}`
}

export function isPdfFile(filename?: string | null, mimeType?: string | null): boolean {
	return Boolean(filename?.toLowerCase().endsWith('.pdf') || mimeType === 'application/pdf')
}
