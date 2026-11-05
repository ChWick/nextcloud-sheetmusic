import type { LibraryData, Song } from '../../types/song'
import {
	ENTITY_COLUMNS,
	SONG_SCALAR_FIELDS,
	type EntityColumnDef,
	type SongEntityProp,
	type SongScalarFieldDef,
	type SongScalarProp,
} from '../songs/song-view-columns'

export {
	ENTITY_COLUMNS,
	SONG_SCALAR_FIELDS,
	type EntityColumnDef,
	type SongEntityProp,
	type SongScalarFieldDef,
	type SongScalarProp,
}

export interface SelectOption {
	id: number
	label: string
}

export interface SongEditableDraft {
	title: string
	sortTitle: string
	entities: Record<SongEntityProp, SelectOption[]>
	scalars: Record<SongScalarProp, string | number | null>
}

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
	instrumentIds: number[]
	arrangerIds: number[]
	opus: string
	catalogNumber: string
	songNumber: string
	stars: number | null
	difficulty: number | null
	duration: number | null
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
export type FieldName = 'title' | SongEntityProp | SongScalarProp

export function entityName<T extends { id: number; name?: string; title?: string; year?: number }>(e: T): string {
	return e.name ?? e.title ?? String((e as { year?: number }).year ?? e.id)
}

export function toSelectOptions<T extends { id: number; name?: string; title?: string; year?: number }>(items: T[] = []): SelectOption[] {
	return items.map(e => ({ id: e.id, label: entityName(e) }))
}

export function areEntitiesEqual(a?: { id: number }[], b?: { id: number }[]): boolean {
	const idsA = (a ?? []).map(e => e.id).sort((x, y) => x - y)
	const idsB = (b ?? []).map(e => e.id).sort((x, y) => x - y)
	if (idsA.length !== idsB.length) return false
	return idsA.every((id, idx) => id === idsB[idx])
}

export function formatEntityList(items?: { id?: number; name?: string; title?: string; year?: number }[], noneLabel = '(None)'): string {
	if (!items || items.length === 0) return noneLabel
	return items.map(e => entityName({ id: e.id ?? 0, name: e.name, title: e.title, year: e.year })).join(', ')
}

function getNormalizedScalar(song: Song | undefined, field: SongScalarFieldDef): string | number | null {
	const raw = (song as Record<string, unknown> | undefined)?.[field.key]
	if (field.type === 'number') {
		const num = Number(raw)
		return Number.isFinite(num) && num > 0 ? num : null
	}
	return String(raw ?? '')
}

export function createDraftFromSong(song?: Song): SongEditableDraft {
	const entities = {} as Record<SongEntityProp, SelectOption[]>
	for (const col of ENTITY_COLUMNS) {
		const list = (song as Record<string, unknown> | undefined)?.[col.songProp] as { id: number; name?: string; title?: string; year?: number }[] | undefined
		entities[col.songProp] = toSelectOptions(list)
	}

	const scalars = {} as Record<SongScalarProp, string | number | null>
	for (const field of SONG_SCALAR_FIELDS) {
		scalars[field.key] = getNormalizedScalar(song, field)
	}

	return {
		title: song?.title ?? '',
		sortTitle: song?.sortTitle ?? '',
		entities,
		scalars,
	}
}

export function createMergedDraft(songA?: Song, songB?: Song): SongEditableDraft {
	if (!songA || !songB) {
		return createDraftFromSong(songA ?? songB)
	}

	const entities = {} as Record<SongEntityProp, SelectOption[]>
	for (const col of ENTITY_COLUMNS) {
		const listA = (songA as Record<string, unknown>)[col.songProp] as { id: number; name?: string; title?: string; year?: number }[] | undefined
		const listB = (songB as Record<string, unknown>)[col.songProp] as { id: number; name?: string; title?: string; year?: number }[] | undefined
		entities[col.songProp] = areEntitiesEqual(listA, listB) ? toSelectOptions(listA) : []
	}

	const scalars = {} as Record<SongScalarProp, string | number | null>
	for (const field of SONG_SCALAR_FIELDS) {
		const valA = getNormalizedScalar(songA, field)
		const valB = getNormalizedScalar(songB, field)
		if (field.type === 'number') {
			scalars[field.key] = valA === valB ? valA : null
		} else {
			scalars[field.key] = String(valA).trim() === String(valB).trim() ? String(valA) : ''
		}
	}

	return {
		title: songA.title.trim() === songB.title.trim() ? songA.title : '',
		sortTitle: (songA.sortTitle ?? '').trim() === (songB.sortTitle ?? '').trim() ? (songA.sortTitle ?? '') : '',
		entities,
		scalars,
	}
}

export function copyFieldIntoDraft(draft: SongEditableDraft, field: FieldName, source?: Song): void {
	if (!source) return
	if (field === 'title') {
		draft.title = source.title ?? ''
		return
	}
	const entityCol = ENTITY_COLUMNS.find(c => c.songProp === field)
	if (entityCol) {
		const list = (source as Record<string, unknown>)[entityCol.songProp] as { id: number; name?: string; title?: string; year?: number }[] | undefined
		draft.entities[entityCol.songProp] = toSelectOptions(list)
		return
	}
	const scalarField = SONG_SCALAR_FIELDS.find(f => f.key === field)
	if (scalarField) {
		draft.scalars[scalarField.key] = getNormalizedScalar(source, scalarField)
	}
}

export function copyDraftToDraft(from: SongEditableDraft, to: SongEditableDraft): void {
	to.title = from.title
	to.sortTitle = from.sortTitle
	for (const col of ENTITY_COLUMNS) {
		to.entities[col.songProp] = [...(from.entities[col.songProp] ?? [])]
	}
	for (const field of SONG_SCALAR_FIELDS) {
		to.scalars[field.key] = from.scalars[field.key]
	}
}

export function copySingleFieldDraftToDraft(from: SongEditableDraft, to: SongEditableDraft, field: FieldName): void {
	if (field === 'title') {
		to.title = from.title
		return
	}
	const entityCol = ENTITY_COLUMNS.find(c => c.songProp === field)
	if (entityCol) {
		to.entities[entityCol.songProp] = [...(from.entities[entityCol.songProp] ?? [])]
		return
	}
	const scalarField = SONG_SCALAR_FIELDS.find(f => f.key === field)
	if (scalarField) {
		to.scalars[scalarField.key] = from.scalars[scalarField.key]
	}
}

export function draftToMergedSongData(draft: SongEditableDraft): MergedSongData {
	const entityIdMap = {} as Record<string, number[]>
	for (const col of ENTITY_COLUMNS) {
		entityIdMap[col.idField] = (draft.entities[col.songProp] ?? []).map(e => e.id)
	}

	const toPositiveIntOrNull = (val: unknown): number | null => {
		const num = Number(val)
		return Number.isFinite(num) && num > 0 ? num : null
	}

	return {
		title: draft.title,
		composerIds: entityIdMap.composerIds ?? [],
		bookIds: entityIdMap.bookIds ?? [],
		collectionIds: entityIdMap.collectionIds ?? [],
		artistIds: entityIdMap.artistIds ?? [],
		genreIds: entityIdMap.genreIds ?? [],
		keyIds: entityIdMap.keyIds ?? [],
		customGroupIds: entityIdMap.customGroupIds ?? [],
		yearIds: entityIdMap.yearIds ?? [],
		signatureIds: entityIdMap.signatureIds ?? [],
		sourceTypeIds: entityIdMap.sourceTypeIds ?? [],
		instrumentIds: entityIdMap.instrumentIds ?? [],
		arrangerIds: entityIdMap.arrangerIds ?? [],
		opus: String(draft.scalars.opus ?? ''),
		catalogNumber: String(draft.scalars.catalogNumber ?? ''),
		songNumber: String(draft.scalars.songNumber ?? ''),
		stars: toPositiveIntOrNull(draft.scalars.stars),
		difficulty: toPositiveIntOrNull(draft.scalars.difficulty),
		duration: toPositiveIntOrNull(draft.scalars.duration),
		keywords: String(draft.scalars.keywords ?? ''),
		custom: String(draft.scalars.custom ?? ''),
		custom2: String(draft.scalars.custom2 ?? ''),
		sortTitle: draft.sortTitle,
	}
}

export function buildLibraryOptionsMap(
	libraryData?: Partial<LibraryData>,
	songA?: Song,
	songB?: Song,
): Record<SongEntityProp, SelectOption[]> {
	const result = {} as Record<SongEntityProp, SelectOption[]>
	for (const col of ENTITY_COLUMNS) {
		const seen = new Map<number, SelectOption>()
		const sources = [
			(libraryData as Record<string, unknown> | undefined)?.[col.songProp],
			(songA as Record<string, unknown> | undefined)?.[col.songProp],
			(songB as Record<string, unknown> | undefined)?.[col.songProp],
		]
		for (const src of sources) {
			if (!Array.isArray(src)) continue
			for (const opt of toSelectOptions(src)) {
				if (!seen.has(opt.id)) {
					seen.set(opt.id, opt)
				}
			}
		}
		result[col.songProp] = Array.from(seen.values())
	}
	return result
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
