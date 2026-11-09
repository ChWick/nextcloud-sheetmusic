import type { components } from './api.generated'

export type Library = components['schemas']['Library']

export interface Sublibrary {
	id?: number
	libraryId: number
	name: string
	slug: string
	filters: Record<string, string[] | string>
	createdAt?: number
	updatedAt?: number
}
