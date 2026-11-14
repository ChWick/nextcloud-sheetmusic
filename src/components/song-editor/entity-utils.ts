export type EntityLabelField = 'name' | 'title'
export type SongEntityIdField =
	| 'composerIds'
	| 'bookIds'
	| 'collectionIds'
	| 'artistIds'
	| 'genreIds'
	| 'keyIds'
	| 'customGroupIds'
	| 'yearIds'
	| 'signatureIds'
	| 'sourceTypeIds'
	| 'instrumentIds'
	| 'arrangerIds'

type EntityOption = {
	id: number
	name?: string
	title?: string
}

type SelectedEntity = {
	id?: number
	name?: string
	title?: string
}

export interface SongEntitySelection {
	idField: SongEntityIdField
	endpoint: string
	labelField: EntityLabelField
	selected: readonly SelectedEntity[]
	options: readonly EntityOption[]
}

export function matchSelected<T extends { id: number }>(options: T[], selected?: { id: number }[]): T[] {
	const ids = new Set((selected ?? []).map((item) => item.id))
	return options.filter((option) => ids.has(option.id))
}

export function findExistingEntity<T extends { id: number }>(
	options: T[],
	label: string,
	labelOf: (entity: T) => string,
): T | undefined {
	const normalizedLabel = label.trim().toLocaleLowerCase()
	if (normalizedLabel === '') {
		return undefined
	}
	return options.find((option) => labelOf(option).trim().toLocaleLowerCase() === normalizedLabel)
}

export function createEntityTag<T extends { id: number }, TTag>(
	options: T[],
	labelOf: (entity: T) => string,
	makeTag: (name: string) => TTag,
): (name: string) => T | TTag {
	return (name) => findExistingEntity(options, name, labelOf) ?? makeTag(name)
}

export function createNamedTag<T extends { id: number; name: string }>(
	options: T[],
): (name: string) => T | { name: string } {
	return createEntityTag(options, (entity) => entity.name, (name) => ({ name }))
}

export function prefillByName<T extends { id: number }, TTag>(
	names: string[] | undefined,
	options: T[],
	nameOf: (item: T) => string,
	makeTag: (name: string) => TTag,
): Array<T | TTag> {
	return (names ?? []).map((name) =>
		options.find((option) => nameOf(option).toLowerCase() === name.toLowerCase()) ?? makeTag(name),
	)
}

export async function resolveSongEntityIds(
	selections: SongEntitySelection[],
	createEntity: (endpoint: string, labelField: EntityLabelField, label: string) => Promise<number>,
): Promise<Record<SongEntityIdField, number[]>> {
	const idsByField: Record<SongEntityIdField, number[]> = {
		composerIds: [],
		bookIds: [],
		collectionIds: [],
		artistIds: [],
		genreIds: [],
		keyIds: [],
		customGroupIds: [],
		yearIds: [],
		signatureIds: [],
		sourceTypeIds: [],
		instrumentIds: [],
		arrangerIds: [],
	}

	const pendingByKey = new Map<string, Promise<number>>()
	const pendingPromises: Promise<number>[] = []
	const pendingAssigners: Array<(id: number) => void> = []

	for (const selection of selections) {
		const optionsByLabel = new Map<string, number>()
		for (const option of selection.options) {
			const label = (option[selection.labelField] ?? '').trim().toLocaleLowerCase()
			if (label !== '' && !optionsByLabel.has(label)) {
				optionsByLabel.set(label, option.id)
			}
		}
		const ids: number[] = new Array(selection.selected.length)
		idsByField[selection.idField] = ids

		for (let i = 0; i < selection.selected.length; i++) {
			const selected = selection.selected[i]
			if (typeof selected.id === 'number') {
				ids[i] = selected.id
				continue
			}

			const label = selected[selection.labelField] ?? ''
			const normalizedLabel = label.trim().toLocaleLowerCase()
			const existingId = normalizedLabel === '' ? undefined : optionsByLabel.get(normalizedLabel)
			if (existingId !== undefined) {
				ids[i] = existingId
				continue
			}

			const dedupKey = normalizedLabel === '' ? '' : `${selection.endpoint}:${normalizedLabel}`
			let createPromise = dedupKey === '' ? undefined : pendingByKey.get(dedupKey)
			if (createPromise === undefined) {
				createPromise = createEntity(selection.endpoint, selection.labelField, label)
				if (dedupKey !== '') {
					pendingByKey.set(dedupKey, createPromise)
				}
			}

			const index = i
			pendingPromises.push(createPromise)
			pendingAssigners.push((createdId) => {
				ids[index] = createdId
			})
		}
	}

	if (pendingPromises.length === 1) {
		pendingAssigners[0](await pendingPromises[0])
	} else if (pendingPromises.length > 1) {
		const resolvedIds = await Promise.all(pendingPromises)
		for (let i = 0; i < resolvedIds.length; i++) {
			pendingAssigners[i](resolvedIds[i])
		}
	}

	return idsByField
}
