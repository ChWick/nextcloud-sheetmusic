if (typeof Promise.withResolvers === 'undefined') {
	Promise.withResolvers = function <T>() {
		let resolve!: (value: T | PromiseLike<T>) => void
		let reject!: (reason?: any) => void
		const promise = new Promise<T>((res, rej) => {
			resolve = res
			reject = rej
		})
		return { promise, resolve, reject }
	}
}

if (typeof (Promise as any).try === 'undefined') {
	;(Promise as any).try = function (fn: any, ...args: any[]) {
		return new Promise((resolve) => resolve(fn(...args)))
	}
}

if (typeof (Uint8Array.prototype as any).toHex === 'undefined') {
	;(Uint8Array.prototype as any).toHex = function () {
		return Array.from(this, (b: number) => b.toString(16).padStart(2, '0')).join('')
	}
}

if (typeof (globalThis as any).Iterator === 'undefined') {
	;(globalThis as any).Iterator = class {}
}

if (typeof (Map as any).groupBy === 'undefined') {
	;(Map as any).groupBy = function <K, T>(items: Iterable<T>, keySelector: (item: T) => K): Map<K, T[]> {
		const map = new Map<K, T[]>()
		for (const item of items) {
			const key = keySelector(item)
			const list = map.get(key)
			if (list) {
				list.push(item)
			} else {
				map.set(key, [item])
			}
		}
		return map
	}
}
