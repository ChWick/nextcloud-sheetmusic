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
	}

	for (const selection of selections) {
		const optionsByLabel = new Map<string, number>()
		for (const option of selection.options) {
			const label = (option[selection.labelField] ?? '').trim().toLocaleLowerCase()
			if (label !== '' && !optionsByLabel.has(label)) {
				optionsByLabel.set(label, option.id)
			}
		}
		const ids: number[] = []

		for (const selected of selection.selected) {
			if (typeof selected.id === 'number') {
				ids.push(selected.id)
				continue
			}

			const label = selected[selection.labelField] ?? ''
			const normalizedLabel = label.trim().toLocaleLowerCase()
			const existingId = normalizedLabel === '' ? undefined : optionsByLabel.get(normalizedLabel)
			ids.push(existingId ?? await createEntity(selection.endpoint, selection.labelField, label))
		}

		idsByField[selection.idField] = ids
	}

	return idsByField
}
