<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import { slugify } from '../../utils/string'
import type { Sublibrary } from '../../types/library'

interface FormFilterRule {
	id: number
	type: string
	values: string[]
}

const props = defineProps<{
	isOpen: boolean
	sublibrary: Sublibrary | null
	libraryFolderPath: string
	isSaving: boolean
	formError: string
	instruments: { id?: number; name?: string }[]
	composers: { id?: number; name?: string }[]
	genres: { id?: number; name?: string }[]
	collections: { id?: number; name?: string }[]
	artists: { id?: number; name?: string }[]
	books: { id?: number; name?: string; title?: string }[]
}>()

const emit = defineEmits<{
	save: [data: { name: string; slug: string; filters: Record<string, string[]> }]
	close: []
}>()

const ENTITY_TYPE_OPTIONS = [
	{ id: 'instrument', label: t('sheetmusic', 'Instrument') },
	{ id: 'composer', label: t('sheetmusic', 'Composer') },
	{ id: 'genre', label: t('sheetmusic', 'Genre') },
	{ id: 'collection', label: t('sheetmusic', 'Collection') },
	{ id: 'artist', label: t('sheetmusic', 'Artist') },
	{ id: 'book', label: t('sheetmusic', 'Book') },
]

const formName = ref('')
const formSlug = ref('')
const isSlugCustomized = ref(false)
const formFilterRules = ref<FormFilterRule[]>([])
const localError = ref('')

let nextRuleId = 1
function addFilterRule(type = 'instrument', values: string[] = []) {
	formFilterRules.value.push({
		id: nextRuleId++,
		type,
		values: [...values],
	})
}

function removeFilterRule(id: number) {
	formFilterRules.value = formFilterRules.value.filter(r => r.id !== id)
	if (formFilterRules.value.length === 0) {
		addFilterRule()
	}
}

function handleRuleTypeChange(rule: FormFilterRule, newType: string) {
	rule.type = newType
	rule.values = []
}

function handleNameInput(val: string | null | undefined) {
	const str = String(val ?? '')
	formName.value = str
	if (!props.sublibrary && !isSlugCustomized.value) {
		formSlug.value = slugify(str)
	}
}

function handleSlugInput(val: string | null | undefined) {
	const str = String(val ?? '')
	formSlug.value = str
	isSlugCustomized.value = true
}

const previewSlug = computed(() => {
	const custom = formSlug.value.trim()
	if (custom) {
		return slugify(custom)
	}
	const fromName = slugify(formName.value.trim())
	return fromName || 'slug'
})

const resultingSublibraryPath = computed(() => {
	return `${props.libraryFolderPath}/_SubLibraries_/${previewSlug.value}`
})

function getEntityList(type: string): { id?: number; name?: string; title?: string }[] {
	switch (type) {
		case 'instrument':
			return props.instruments ?? []
		case 'composer':
			return props.composers ?? []
		case 'genre':
			return props.genres ?? []
		case 'collection':
			return props.collections ?? []
		case 'artist':
			return props.artists ?? []
		case 'book':
			return props.books ?? []
		default:
			return []
	}
}

function getEntityOptions(type: string, currentValues: string[] = []): { id: string; label: string }[] {
	const list = getEntityList(type)
	const seen = new Set<string>()
	const options: { id: string; label: string }[] = []

	for (const item of list) {
		const rawName = ('title' in item && item.title) ? item.title : item.name
		const name = (rawName ?? '').trim()
		if (name && !seen.has(name.toLowerCase())) {
			seen.add(name.toLowerCase())
			options.push({ id: name, label: name })
		}
	}

	for (const val of currentValues) {
		const name = (val ?? '').trim()
		if (name && !seen.has(name.toLowerCase())) {
			seen.add(name.toLowerCase())
			options.push({ id: name, label: name })
		}
	}

	return options.sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: 'base' }))
}

watch(
	() => props.isOpen,
	(open) => {
		if (!open) return
		localError.value = ''
		if (props.sublibrary) {
			formName.value = props.sublibrary.name
			formSlug.value = props.sublibrary.slug
			isSlugCustomized.value = true
			formFilterRules.value = []

			const filters = props.sublibrary.filters ?? {}
			for (const [type, values] of Object.entries(filters)) {
				const vals = Array.isArray(values) ? values : [values]
				addFilterRule(type, vals.map(v => String(v).trim()).filter(Boolean))
			}
			if (formFilterRules.value.length === 0) {
				addFilterRule('instrument', [])
			}
		} else {
			formName.value = ''
			formSlug.value = ''
			isSlugCustomized.value = false
			formFilterRules.value = []
			addFilterRule('instrument', [])
		}
	},
	{ immediate: true },
)

function handleSave() {
	localError.value = ''
	const name = formName.value.trim()
	if (!name) {
		localError.value = t('sheetmusic', 'Sub-library name is required.')
		return
	}

	const slug = formSlug.value.trim() !== '' ? slugify(formSlug.value.trim()) : slugify(name)
	if (!slug) {
		localError.value = t('sheetmusic', 'A valid folder slug is required.')
		return
	}

	const filters: Record<string, string[]> = {}
	let hasFilterValues = false

	for (const rule of formFilterRules.value) {
		const vals = (rule.values || []).map(v => v.trim()).filter(Boolean)
		if (vals.length > 0) {
			filters[rule.type] = Array.from(new Set([...(filters[rule.type] || []), ...vals]))
			hasFilterValues = true
		}
	}

	if (!hasFilterValues) {
		localError.value = t('sheetmusic', 'Please select at least one filter value.')
		return
	}

	emit('save', { name, slug, filters })
}
</script>

<template>
	<NcDialog
		v-if="isOpen"
		:name="sublibrary ? t('sheetmusic', 'Edit Sub-Library') : t('sheetmusic', 'Create Sub-Library')"
		size="normal"
		@close="!isSaving && emit('close')">
		<div :class="$style.dialogBody">
			<NcTextField
				:model-value="formName"
				:label="t('sheetmusic', 'Sub-library Name')"
				placeholder="e.g. Organ Repertoire"
				@update:model-value="handleNameInput" />

			<NcTextField
				:model-value="formSlug"
				:label="t('sheetmusic', 'Folder Slug (used in WebDAV path)')"
				placeholder="e.g. organ-repertoire"
				@update:model-value="handleSlugInput" />

			<div :class="$style.resultingPathBox">
				<span :class="$style.resultingPathLabel">{{ t('sheetmusic', 'Resulting WebDAV folder path for MobileSheets:') }}</span>
				<code :class="$style.resultingPathCode">{{ resultingSublibraryPath }}</code>
			</div>

			<div :class="$style.formSectionHeader">
				<h4>{{ t('sheetmusic', 'Filter Criteria') }}</h4>
				<p :class="$style.formSectionSub">
					{{ t('sheetmusic', 'Only songs matching these criteria will be included in this sub-library.') }}
				</p>
			</div>

			<div :class="$style.ruleList">
				<div
					v-for="rule in formFilterRules"
					:key="rule.id"
					:class="$style.ruleRow">
					<NcSelect
						:model-value="rule.type"
						:options="ENTITY_TYPE_OPTIONS"
						:reduce="(opt: any) => opt.id"
						label="label"
						:input-label="t('sheetmusic', 'Filter by')"
						:clearable="false"
						:class="$style.ruleSelect"
						@update:model-value="handleRuleTypeChange(rule, $event)" />

					<NcSelect
						v-model="rule.values"
						:options="getEntityOptions(rule.type, rule.values)"
						:reduce="(opt: any) => opt.id"
						label="label"
						track-by="id"
						:multiple="true"
						:close-on-select="false"
						:taggable="false"
						:input-label="t('sheetmusic', 'Select values')"
						:placeholder="t('sheetmusic', 'Select values…')"
						:class="$style.ruleValuesSelect" />

					<NcButton
						v-if="formFilterRules.length > 1"
						type="button"
						variant="tertiary"
						:title="t('sheetmusic', 'Remove filter rule')"
						:class="$style.removeRuleBtn"
						@click="removeFilterRule(rule.id)">
						<template #icon>
							<Close :size="18" />
						</template>
					</NcButton>
				</div>
			</div>

			<NcButton
				type="button"
				:class="$style.addRuleBtn"
				@click="addFilterRule()">
				<template #icon>
					<Plus :size="16" />
				</template>
				{{ t('sheetmusic', 'Add another filter criterion') }}
			</NcButton>

			<p v-if="localError || formError" :class="$style.error" role="alert">
				{{ localError || formError }}
			</p>
		</div>

		<template #actions>
			<NcButton
				type="button"
				variant="primary"
				:disabled="isSaving"
				@click="handleSave">
				<template v-if="isSaving" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ sublibrary ? t('sheetmusic', 'Save Changes') : t('sheetmusic', 'Create Sub-Library') }}
			</NcButton>
			<NcButton
				type="button"
				:disabled="isSaving"
				@click="emit('close')">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.dialogBody {
	padding: 8px 0;
}

.resultingPathBox {
	margin: 12px 0 18px 0;
	padding: 10px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
	border: 1px solid var(--color-border);
}

.resultingPathLabel {
	display: block;
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.resultingPathCode {
	display: block;
	font-family: monospace;
	font-size: 0.85rem;
	padding: 4px 8px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	color: var(--color-primary-element-text, var(--color-main-text));
	word-break: break-all;
}

.formSectionHeader {
	margin: 18px 0 10px 0;
	padding-top: 14px;
	border-top: 1px solid var(--color-border);
}

.formSectionHeader h4 {
	margin: 0 0 4px 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.formSectionSub {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.ruleList {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-bottom: 12px;
}

.ruleRow {
	display: flex;
	align-items: center;
	gap: 10px;
}

.ruleSelect {
	flex: 0 0 170px;
}

.ruleValuesSelect {
	flex: 1;
}

.removeRuleBtn {
	flex-shrink: 0;
}

.addRuleBtn {
	margin-top: 4px;
}

.error {
	color: var(--color-error);
	margin-top: 12px;
	font-size: 0.9rem;
}
</style>
