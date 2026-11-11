import type { components } from './api.generated'

export type Library = components['schemas']['Library'] & {
	folderId?: number | null
	isShared?: boolean
	ownerId?: string | null
	ownerDisplayName?: string | null
	permission?: 'admin' | 'write' | 'read'
	canWrite?: boolean
}

export interface LibraryShare {
	id: number | string
	fullId: string
	shareType: number
	shareWith: string
	shareWithDisplayName?: string
	permission: 'read' | 'write'
	canWrite: boolean
	token?: string | null
	publicUrl?: string | null
	webdavUrl?: string | null
}

export interface Sublibrary {
	id?: number
	libraryId: number
	name: string
	slug: string
	filters: Record<string, string[] | string>
	createdAt?: number
	updatedAt?: number
}

export interface SharesOverview {
	library: {
		id: number
		name: string
		path: string
		shares: LibraryShare[]
	}
	sublibraries: Array<{
		id: number
		name: string
		slug: string
		filters: Record<string, string[] | string>
		shares: LibraryShare[]
	}>
}
