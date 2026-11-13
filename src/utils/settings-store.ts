/**
 * Storage adapter interface to decouple persistence from browser localStorage,
 * allowing in-memory storage, session storage, or remote sync.
 */
export interface StorageAdapter {
	getItem(key: string): string | null
	setItem(key: string, value: string): void
	removeItem(key: string): void
}

/**
 * In-memory storage adapter for testing and environments without localStorage.
 */
export class MemoryStorageAdapter implements StorageAdapter {
	private store = new Map<string, string>()

	getItem(key: string): string | null {
		return this.store.get(key) ?? null
	}

	setItem(key: string, value: string): void {
		this.store.set(key, String(value))
	}

	removeItem(key: string): void {
		this.store.delete(key)
	}

	clear(): void {
		this.store.clear()
	}
}

/**
 * LocalStorage adapter that safely guards against access restrictions,
 * quota errors, and missing browser globals, falling back to memory.
 */
export class LocalStorageAdapter implements StorageAdapter {
	private fallback = new MemoryStorageAdapter()

	private isStorageAvailable(): boolean {
		if (typeof window === 'undefined' || !window.localStorage) {
			return false
		}
		try {
			const probe = '__sm_storage_test__'
			window.localStorage.setItem(probe, '1')
			window.localStorage.removeItem(probe)
			return true
		} catch {
			return false
		}
	}

	getItem(key: string): string | null {
		try {
			if (this.isStorageAvailable()) {
				return window.localStorage.getItem(key)
			}
		} catch {
			// Fall through to memory fallback
		}
		return this.fallback.getItem(key)
	}

	setItem(key: string, value: string): void {
		try {
			if (this.isStorageAvailable()) {
				window.localStorage.setItem(key, value)
				return
			}
		} catch {
			// Fall through to memory fallback
		}
		this.fallback.setItem(key, value)
	}

	removeItem(key: string): void {
		try {
			if (this.isStorageAvailable()) {
				window.localStorage.removeItem(key)
				return
			}
		} catch {
			// Fall through to memory fallback
		}
		this.fallback.removeItem(key)
	}
}

/**
 * Generic options for initializing a SettingsStore.
 */
export interface SettingsStoreOptions<T> {
	storageKey: string
	defaultSettings: T | (() => T)
	storage?: StorageAdapter
	validator?: (raw: unknown) => T
	version?: number
	migrate?: (savedVersion: number, raw: any) => T
}

/**
 * Generic settings store that handles serialization, deserialization,
 * validation, versioning, migrations, and default fallback.
 */
export class SettingsStore<T extends Record<string, any>> {
	private storageKey: string
	private defaultSettingsFactory: () => T
	private storage: StorageAdapter
	private validator?: (raw: unknown) => T
	private version?: number
	private migrate?: (savedVersion: number, raw: any) => T

	constructor(options: SettingsStoreOptions<T>) {
		this.storageKey = options.storageKey
		this.defaultSettingsFactory = typeof options.defaultSettings === 'function'
			? (options.defaultSettings as () => T)
			: () => JSON.parse(JSON.stringify(options.defaultSettings))
		this.storage = options.storage ?? new LocalStorageAdapter()
		this.validator = options.validator
		this.version = options.version
		this.migrate = options.migrate
	}

	public get(): T {
		const raw = this.storage.getItem(this.storageKey)
		if (!raw) {
			return this.defaultSettingsFactory()
		}
		try {
			let parsed = JSON.parse(raw)
			if (this.version !== undefined && parsed && typeof parsed === 'object') {
				const savedVer = typeof parsed.version === 'number' ? parsed.version : 0
				if (savedVer < this.version && this.migrate) {
					parsed = this.migrate(savedVer, parsed)
				}
			}
			if (this.validator) {
				return this.validator(parsed)
			}
			return { ...this.defaultSettingsFactory(), ...parsed }
		} catch {
			return this.defaultSettingsFactory()
		}
	}

	public save(settings: T): void {
		try {
			const toSave = {
				...settings,
				...(this.version !== undefined ? { version: this.version } : {}),
			}
			this.storage.setItem(this.storageKey, JSON.stringify(toSave))
		} catch (err) {
			console.warn(`[SettingsStore] Failed to save settings for key "${this.storageKey}":`, err)
		}
	}

	public set(partial: Partial<T>): void {
		const current = this.get()
		this.save({ ...current, ...partial })
	}

	public update(updater: (current: T) => T): void {
		const current = this.get()
		this.save(updater(current))
	}

	public reset(): void {
		this.storage.removeItem(this.storageKey)
	}

	public getSetting<K extends keyof T>(key: K): T[K] {
		return this.get()[key]
	}

	public setSetting<K extends keyof T>(key: K, value: T[K]): void {
		this.set({ [key]: value } as unknown as Partial<T>)
	}
}
