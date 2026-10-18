import type { Song } from '../../types/song'

export interface MergedSongData {
	title: string
	composerIds: number[]
	bookIds: number[]
	collectionIds: number[]
	artistIds: number[]
	genreIds: number[]
	keyIds: number[]
	customGroupIds: number[]
	yearIds: number[]
	signatureIds: number[]
	sourceTypeIds: number[]
	instrumentIds?: number[]
	opus?: string
	catalogNumber?: string
	songNumber?: string
	stars: number
	difficulty: number
	duration: number
	keywords: string
	custom: string
	custom2: string
	sortTitle: string
}

export interface ResolveBothSongData extends MergedSongData {
	id: number
	detachFileIds: number[]
}

export type MergeMode = 'merge' | 'keepBoth'
export type FieldName = 'title' | 'composers' | 'books' | 'collections' | 'artists' | 'genres' | 'keys' | 'keywords'

export function entityName<T extends { id: number; name?: string; title?: string; year?: number }>(e: T): string {
	return e.name ?? e.title ?? String((e as { year?: number }).year ?? e.id)
}

export function toSelectOptions<T extends { id: number; name?: string; title?: string; year?: number }>(items: T[] = []): { id: number; label: string }[] {
	return items.map(e => ({ id: e.id, label: entityName(e) }))
}

export function areEntitiesEqual(a?: { id: number }[], b?: { id: number }[]): boolean {
	const idsA = (a ?? []).map(e => e.id).sort((x, y) => x - y)
	const idsB = (b ?? []).map(e => e.id).sort((x, y) => x - y)
	if (idsA.length !== idsB.length) return false
	return idsA.every((id, idx) => id === idsB[idx])
}

export function formatEntityList(items?: { name?: string; title?: string }[], noneLabel = '(None)'): string {
	if (!items || items.length === 0) return noneLabel
	return items.map(entityName).join(', ')
}

export interface CheckCollisionParams {
	songA?: Song
	songB?: Song
	titleA: string
	titleB: string
	composersA: { id: number }[]
	composersB: { id: number }[]
	artistsA: { id: number }[]
	artistsB: { id: number }[]
	booksA: { id: number }[]
	booksB: { id: number }[]
	collectionsA: { id: number }[]
	collectionsB: { id: number }[]
	detachedFilesA: number[]
	detachedFilesB: number[]
}

export function wouldSongsCollide(params: CheckCollisionParams): boolean {
	const {
		songA,
		songB,
		titleA,
		titleB,
		composersA,
		composersB,
		artistsA,
		artistsB,
		booksA,
		booksB,
		collectionsA,
		collectionsB,
		detachedFilesA,
		detachedFilesB,
	} = params

	const activeFilesA = (songA?.files ?? []).filter(f => !detachedFilesA.includes(f.id))
	const activeFilesB = (songB?.files ?? []).filter(f => !detachedFilesB.includes(f.id))

	// Check 1: Shared physical file path collision
	if (activeFilesA.length > 0 && activeFilesB.length > 0) {
		const pathsA = new Set(activeFilesA.map(f => f.path))
		const sharesPhysicalFile = activeFilesB.some(f => pathsA.has(f.path))
		if (sharesPhysicalFile) {
			return true
		}
	}

	// Check 2: Naming rule / target path collision
	const sameTitle = titleA.trim().toLowerCase() === titleB.trim().toLowerCase()
	const sameComposers = areEntitiesEqual(composersA, composersB)
	const sameArtists = areEntitiesEqual(artistsA, artistsB)
	const sameBooks = areEntitiesEqual(booksA, booksB)
	const sameCollections = areEntitiesEqual(collectionsA, collectionsB)

	if (sameTitle && sameComposers && sameArtists && sameBooks && sameCollections && activeFilesA.length > 0 && activeFilesB.length > 0) {
		const extsA = new Set(activeFilesA.map(f => {
			const ext = f.path.split('.').pop()
			return ext ? ext.toLowerCase() : ''
		}))
		const hasOverlappingExtension = activeFilesB.some(f => {
			const ext = f.path.split('.').pop()
			return extsA.has(ext ? ext.toLowerCase() : '')
		})
		if (hasOverlappingExtension) {
			return true
		}
	}

	return false
}

