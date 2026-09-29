export function getFileName(filePath: string): string {
	return filePath.split('/').pop() ?? filePath
}

export function openFileExternally(filePath: string): void {
	window.open(filePath, '_blank', 'noopener,noreferrer')
}
