/**
 * Interface for binary file payload caching.
 */
export interface FileBinaryCache {
	get(key: string): Uint8Array | null
	set(key: string, data: Uint8Array): void
	has(key: string): boolean
	delete(key: string): boolean
	clear(): void
	size(): number
}

/**
 * In-memory LRU binary cache.
 * Avoids redundant network downloads when navigating between songs,
 * while automatically evicting oldest entries to cap memory usage.
 */
export class MemoryFileBinaryCache implements FileBinaryCache {
	private cache = new Map<string, Uint8Array>()
	private readonly maxEntries: number

	constructor(maxEntries = 20) {
		this.maxEntries = maxEntries
	}

	get(key: string): Uint8Array | null {
		const item = this.cache.get(key)
		if (!item) {
			return null
		}
		// Move to the end of the Map to mark as most recently used (LRU)
		this.cache.delete(key)
		this.cache.set(key, item)
		return item
	}

	set(key: string, data: Uint8Array): void {
		if (this.cache.has(key)) {
			this.cache.delete(key)
		} else if (this.cache.size >= this.maxEntries) {
			// Evict the least recently used item (first item in the Map)
			const oldestKey = this.cache.keys().next().value
			if (oldestKey !== undefined) {
				this.cache.delete(oldestKey)
			}
		}
		this.cache.set(key, data)
	}

	has(key: string): boolean {
		return this.cache.has(key)
	}

	delete(key: string): boolean {
		return this.cache.delete(key)
	}

	clear(): void {
		this.cache.clear()
	}

	size(): number {
		return this.cache.size
	}
}

/**
 * Shared in-memory binary cache instance for previewed PDFs and documents.
 */
export const defaultFileBinaryCache = new MemoryFileBinaryCache(20)
