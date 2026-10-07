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
