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
 * Default maximum total bytes retained in the in-memory binary cache (64 MiB).
 */
export const DEFAULT_MAX_CACHE_BYTES = 64 * 1024 * 1024

/**
 * In-memory LRU binary cache.
 * Avoids redundant network downloads when navigating between songs,
 * while automatically evicting oldest entries to cap both entry count and memory usage.
 */
export class MemoryFileBinaryCache implements FileBinaryCache {
	private cache = new Map<string, Uint8Array>()
	private readonly maxEntries: number
	private readonly maxBytes: number
	private totalBytes = 0

	constructor(maxEntries = 20, maxBytes = DEFAULT_MAX_CACHE_BYTES) {
		this.maxEntries = maxEntries
		this.maxBytes = maxBytes
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
		const existing = this.cache.get(key)
		if (existing) {
			this.totalBytes -= existing.byteLength
			this.cache.delete(key)
		}

		// Do not cache single payloads larger than the entire byte budget
		if (data.byteLength > this.maxBytes) {
			return
		}

		while (
			this.cache.size > 0
			&& (this.cache.size >= this.maxEntries || this.totalBytes + data.byteLength > this.maxBytes)
		) {
			// Evict the least recently used item (first item in the Map)
			const oldestKey = this.cache.keys().next().value
			if (oldestKey === undefined) break
			const oldest = this.cache.get(oldestKey)
			if (oldest) {
				this.totalBytes -= oldest.byteLength
			}
			this.cache.delete(oldestKey)
		}

		this.cache.set(key, data)
		this.totalBytes += data.byteLength
	}

	has(key: string): boolean {
		return this.cache.has(key)
	}

	delete(key: string): boolean {
		const existing = this.cache.get(key)
		if (!existing) return false
		this.totalBytes -= existing.byteLength
		return this.cache.delete(key)
	}

	clear(): void {
		this.cache.clear()
		this.totalBytes = 0
	}

	size(): number {
		return this.cache.size
	}

	byteSize(): number {
		return this.totalBytes
	}
}

/**
 * Shared in-memory binary cache instance for previewed PDFs and documents.
 */
export const defaultFileBinaryCache = new MemoryFileBinaryCache(20)
