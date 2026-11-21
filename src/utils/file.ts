import type { SongFile } from '../types/song'

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

const IMAGE_EXTENSIONS = ['.png', '.jpg', '.jpeg', '.webp', '.gif', '.svg', '.bmp']

export function isPdfFile(filename?: string | null, mimeType?: string | null): boolean {
	const clean = filename?.split('?')[0].toLowerCase() ?? ''
	return Boolean(clean.endsWith('.pdf') || mimeType === 'application/pdf')
}

export function isImageFile(filename?: string | null): boolean {
	const clean = filename?.split('?')[0].toLowerCase() ?? ''
	return IMAGE_EXTENSIONS.some((ext) => clean.endsWith(ext))
}

export function pickPrimarySongFile(
	files?: SongFile[] | null,
	preferredFileId?: string | number | null,
): SongFile | null {
	if (!files || files.length === 0) return null
	if (preferredFileId !== undefined && preferredFileId !== null && preferredFileId !== '') {
		const matched = files.find((f) => String(f.id) === String(preferredFileId))
		if (matched) return matched
	}
	return files.find((f) => isPdfFile(f.path))
		?? files.find((f) => f.isPrimary)
		?? files[0]
		?? null
}

export function formatFileSize(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function toUint8Array(data: unknown): Uint8Array {
	if (data instanceof Uint8Array) return data
	if (data instanceof ArrayBuffer) return new Uint8Array(data)
	return data as Uint8Array
}
