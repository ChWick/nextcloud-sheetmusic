import { describe, expect, it } from 'vitest'
import { defaultFileBinaryCache, MemoryFileBinaryCache } from '../../../src/utils/file-cache'

describe('MemoryFileBinaryCache', () => {
	it('stores and retrieves binary data', () => {
		const cache = new MemoryFileBinaryCache(5)
		const data = new Uint8Array([1, 2, 3, 4])
		cache.set('key1', data)

		expect(cache.has('key1')).toBe(true)
		expect(cache.get('key1')).toEqual(data)
		expect(cache.size()).toBe(1)
	})

	it('returns null on cache miss', () => {
		const cache = new MemoryFileBinaryCache(5)
		expect(cache.get('nonexistent')).toBeNull()
		expect(cache.has('nonexistent')).toBe(false)
	})

	it('deletes specific entries', () => {
		const cache = new MemoryFileBinaryCache(5)
		const data = new Uint8Array([1, 2])
		cache.set('key1', data)
		expect(cache.delete('key1')).toBe(true)
		expect(cache.has('key1')).toBe(false)
		expect(cache.get('key1')).toBeNull()
		expect(cache.delete('key1')).toBe(false)
	})

	it('clears all entries', () => {
		const cache = new MemoryFileBinaryCache(5)
		cache.set('key1', new Uint8Array([1]))
		cache.set('key2', new Uint8Array([2]))
		expect(cache.size()).toBe(2)

		cache.clear()
		expect(cache.size()).toBe(0)
		expect(cache.get('key1')).toBeNull()
		expect(cache.get('key2')).toBeNull()
	})

	it('evicts the least recently used item when maxEntries is exceeded', () => {
		const cache = new MemoryFileBinaryCache(3)
		cache.set('k1', new Uint8Array([1]))
		cache.set('k2', new Uint8Array([2]))
		cache.set('k3', new Uint8Array([3]))

		// k1 should be oldest, adding k4 should evict k1
		cache.set('k4', new Uint8Array([4]))
		expect(cache.has('k1')).toBe(false)
		expect(cache.has('k2')).toBe(true)
		expect(cache.has('k3')).toBe(true)
		expect(cache.has('k4')).toBe(true)
		expect(cache.size()).toBe(3)
	})

	it('updates LRU order on get access', () => {
		const cache = new MemoryFileBinaryCache(3)
		cache.set('k1', new Uint8Array([1]))
		cache.set('k2', new Uint8Array([2]))
		cache.set('k3', new Uint8Array([3]))

		// Access k1, making k2 the oldest
		cache.get('k1')

		// Adding k4 should now evict k2, not k1
		cache.set('k4', new Uint8Array([4]))
		expect(cache.has('k1')).toBe(true)
		expect(cache.has('k2')).toBe(false)
		expect(cache.has('k3')).toBe(true)
		expect(cache.has('k4')).toBe(true)
	})

	it('updates existing keys without increasing size beyond maxEntries', () => {
		const cache = new MemoryFileBinaryCache(2)
		cache.set('k1', new Uint8Array([1]))
		cache.set('k1', new Uint8Array([99]))
		expect(cache.size()).toBe(1)
		expect(cache.get('k1')).toEqual(new Uint8Array([99]))
	})

	it('provides a default singleton cache instance', () => {
		expect(defaultFileBinaryCache).toBeInstanceOf(MemoryFileBinaryCache)
	})
})
