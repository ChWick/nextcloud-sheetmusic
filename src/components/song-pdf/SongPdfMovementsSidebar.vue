<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import type { MovementSegment } from './usePdfMovements'

const props = withDefaults(
	defineProps<{
		movements: MovementSegment[]
		totalPages: number
		selectedPages?: number[]
		isExtractingOutline?: boolean
		autoPdfMetadata?: boolean
	}>(),
	{
		selectedPages: () => [],
		isExtractingOutline: false,
		autoPdfMetadata: true,
	},
)

const emit = defineEmits<{
	'add-movement': []
	'add-movement-from-selection': []
	'remove-movement': [id: string]
	'update-movement': [id: string, updates: Partial<MovementSegment>]
	'assign-selected': [id: string]
	'extract-outline': []
}>()

const selectedRange = computed<{ start: number; end: number } | null>(() => {
	if (!props.selectedPages || props.selectedPages.length === 0) return null
	return {
		start: Math.min(...props.selectedPages),
		end: Math.max(...props.selectedPages),
	}
})

const selectedRangeText = computed(() => {
	if (!selectedRange.value) return ''
	const { start, end } = selectedRange.value
	return start === end ? `${start}` : `${start}–${end}`
})
</script>

<template>
	<aside
		:class="$style.sidebar"
		role="region"
		:aria-label="t('sheetmusic', 'Movements management sidebar')">
		<!-- Actions Header -->
		<div :class="$style.actionsHeader">
			<NcButton
				variant="secondary"
				:disabled="isExtractingOutline"
				:title="t('sheetmusic', 'Auto-detect movements from embedded PDF outline / table of contents')"
				@click="emit('extract-outline')">
				<template #icon>
					<FormatListBulleted :size="18" />
				</template>
				{{ isExtractingOutline ? t('sheetmusic', 'Detecting…') : t('sheetmusic', 'Import from PDF Outline') }}
			</NcButton>
			<NcButton
				v-if="selectedRangeText"
				variant="primary"
				:title="t('sheetmusic', 'Add new movement from selected pages ({range})', { range: selectedRangeText })"
				:aria-label="t('sheetmusic', 'Add from Selection ({range})', { range: selectedRangeText })"
				@click="emit('add-movement-from-selection')">
				<template #icon>
					<PlusIcon :size="18" />
				</template>
				{{ t('sheetmusic', 'Add from Selection ({range})', { range: selectedRangeText }) }}
			</NcButton>
			<NcButton
				variant="secondary"
				:title="t('sheetmusic', 'Add new movement')"
				@click="emit('add-movement')">
				<template #icon>
					<PlusIcon :size="18" />
				</template>
				{{ t('sheetmusic', 'Add Movement') }}
			</NcButton>
		</div>

		<!-- List of movements -->
		<div :class="$style.movementsList">
			<div
				v-if="movements.length === 0"
				:class="$style.emptyState">
				<p>{{ t('sheetmusic', 'No movements defined yet. Add a movement or import from the PDF outline.') }}</p>
			</div>

			<div
				v-for="(mvt, index) in movements"
				:key="mvt.id"
				:class="$style.movementCard"
				:data-test="`movement-card-${index}`">
				<div :class="$style.cardHeader">
					<span :class="$style.cardIndex">#{{ index + 1 }}</span>
					<NcTextField
						:model-value="mvt.name"
						:label="t('sheetmusic', 'Movement Name')"
						hide-label
						:placeholder="t('sheetmusic', 'e.g. I. Allegro')"
						@update:model-value="(val: string) => emit('update-movement', mvt.id, { name: val })" />
					<NcButton
						variant="secondary"
						type="button"
						:title="t('sheetmusic', 'Delete movement')"
						:aria-label="t('sheetmusic', 'Delete movement')"
						@click="emit('remove-movement', mvt.id)">
						<template #icon>
							<DeleteIcon :size="18" />
						</template>
					</NcButton>
				</div>

				<div :class="$style.pageRangeRow">
					<div :class="$style.pageInputs">
						<label :class="$style.pageLabel">
							<span>{{ t('sheetmusic', 'Start') }}</span>
							<input
								type="number"
								min="1"
								:max="totalPages || 9999"
								:value="mvt.pageNum"
								:class="$style.numInput"
								@change="(e: Event) => emit('update-movement', mvt.id, { pageNum: Number((e.target as HTMLInputElement).value) })" />
						</label>
						<span :class="$style.rangeSeparator">—</span>
						<label :class="$style.pageLabel">
							<span>{{ t('sheetmusic', 'End') }}</span>
							<input
								type="number"
								:min="mvt.pageNum"
								:max="totalPages || 9999"
								:value="mvt.endPage ?? mvt.pageNum"
								:class="$style.numInput"
								@change="(e: Event) => emit('update-movement', mvt.id, { endPage: Number((e.target as HTMLInputElement).value) })" />
						</label>
					</div>

					<NcButton
						v-if="selectedRangeText"
						size="small"
						variant="secondary"
						:title="t('sheetmusic', 'Assign selected pages ({range}) to this movement', { range: selectedRangeText })"
						:aria-label="t('sheetmusic', 'Pages {range}', { range: selectedRangeText })"
						@click="emit('assign-selected', mvt.id)">
						<template #icon>
							<ArrowRight :size="14" />
						</template>
						{{ t('sheetmusic', 'Pages {range}', { range: selectedRangeText }) }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Helpful note -->
		<div :class="$style.footerNote">
			<small>{{ t('sheetmusic', 'Movements allow instant navigation across sections and sync seamlessly to MobileSheets.') }}</small>
			<small v-if="autoPdfMetadata" :class="$style.tocSyncNote">
				{{ t('sheetmusic', 'Table of Contents will also be stored in the PDF (library setting enabled).') }}
			</small>
		</div>
	</aside>
</template>

<style module>
.sidebar {
	display: flex;
	flex-direction: column;
	width: 320px;
	border-right: 1px solid var(--color-border);
	background: var(--color-main-background);
	height: 100%;
	flex-shrink: 0;
	box-sizing: border-box;
}

.tocSyncNote {
	margin-top: 6px;
	display: block;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.actionsHeader {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
}

.movementsList {
	flex: 1;
	overflow-y: auto;
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.emptyState {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.movementCard {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 10px;
	background: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cardHeader {
	display: flex;
	align-items: center;
	gap: 8px;
}

.cardIndex {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.pageRangeRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.pageInputs {
	display: flex;
	align-items: center;
	gap: 6px;
}

.pageLabel {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.numInput {
	width: 52px;
	padding: 3px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	text-align: center;
}

.rangeSeparator {
	color: var(--color-text-maxcontrast);
}

.footerNote {
	padding: 10px 12px;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}
</style>
