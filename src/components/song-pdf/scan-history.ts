import type { ScanOperation } from './scan-types'

export class ScanHistoryManager {
	private history: ScanOperation[][] = []
	private currentIndex = -1
	private maxHistory: number

	constructor(initialState?: ScanOperation[], maxHistory = 50) {
		this.maxHistory = maxHistory
		if (initialState !== undefined) {
			this.push(initialState)
		}
	}

	public push(ops: ScanOperation[]): void {
		const snapshot: ScanOperation[] = JSON.parse(JSON.stringify(ops))

		// If current index is behind the end, truncate future redos
		if (this.currentIndex < this.history.length - 1) {
			this.history = this.history.slice(0, this.currentIndex + 1)
		}

		this.history.push(snapshot)
		if (this.history.length > this.maxHistory) {
			this.history.shift()
		} else {
			this.currentIndex++
		}
	}

	public undo(): ScanOperation[] | null {
		if (!this.canUndo()) {
			return null
		}
		this.currentIndex--
		return JSON.parse(JSON.stringify(this.history[this.currentIndex]))
	}

	public redo(): ScanOperation[] | null {
		if (!this.canRedo()) {
			return null
		}
		this.currentIndex++
		return JSON.parse(JSON.stringify(this.history[this.currentIndex]))
	}

	public canUndo(): boolean {
		return this.currentIndex > 0
	}

	public canRedo(): boolean {
		return this.currentIndex < this.history.length - 1
	}

	public getCurrent(): ScanOperation[] {
		if (this.currentIndex >= 0 && this.currentIndex < this.history.length) {
			return JSON.parse(JSON.stringify(this.history[this.currentIndex]))
		}
		return []
	}

	public clear(initialState?: ScanOperation[]): void {
		this.history = []
		this.currentIndex = -1
		if (initialState !== undefined) {
			this.push(initialState)
		}
	}
}
