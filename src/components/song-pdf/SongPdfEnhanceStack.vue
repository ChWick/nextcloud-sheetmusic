<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Plus from 'vue-material-design-icons/Plus.vue'
import Tune from 'vue-material-design-icons/Tune.vue'

import SongPdfEnhanceOperationItem from './SongPdfEnhanceOperationItem.vue'
import type { ScanOperation } from './scan-types'

const props = defineProps<{
	operations: ScanOperation[]
}>()

const emit = defineEmits<{
	'applyPreset': [preset: 'standard' | 'contrast' | 'colorClean' | 'binarize']
	'addOperation': [type: 'gaussian' | 'grayLevelMapping' | 'adaptiveThreshold']
	'toggleOperation': [index: number]
	'moveOperation': [index: number, direction: 'up' | 'down']
	'removeOperation': [index: number]
	'input': []
	'change': []
}>()
</script>

<template>
	<div :class="$style.stackPane">
		<div :class="$style.stackHeader">
			<span :class="$style.stackTitle">
				<Tune :size="18" />
				{{ t('sheetmusic', 'Operation Stack ({count})', { count: operations.length }) }}
			</span>
			<!-- Presets -->
			<div :class="$style.presetBar">
				<span :class="$style.subLabel">{{ t('sheetmusic', 'Presets:') }}</span>
				<button
					type="button"
					:class="$style.presetChip"
					data-test="preset-standard"
					@click="emit('applyPreset', 'standard')">
					{{ t('sheetmusic', 'Clean Sheet') }}
				</button>
				<button
					type="button"
					:class="$style.presetChip"
					data-test="preset-colorClean"
					@click="emit('applyPreset', 'colorClean')">
					{{ t('sheetmusic', 'Color Notes') }}
				</button>
				<button
					type="button"
					:class="$style.presetChip"
					data-test="preset-contrast"
					@click="emit('applyPreset', 'contrast')">
					{{ t('sheetmusic', 'Curves Only') }}
				</button>
				<button
					type="button"
					:class="$style.presetChip"
					data-test="preset-binarize"
					@click="emit('applyPreset', 'binarize')">
					{{ t('sheetmusic', 'B&W') }}
				</button>
			</div>
		</div>

		<!-- Operation Cards List -->
		<div :class="$style.operationsList">
			<SongPdfEnhanceOperationItem
				v-for="(op, idx) in operations"
				:key="op.id"
				:operation="op"
				:index="idx"
				:total="operations.length"
				@toggle="emit('toggleOperation', idx)"
				@move="emit('moveOperation', idx, $event)"
				@remove="emit('removeOperation', idx)"
				@input="emit('input')"
				@change="emit('change')" />

			<div v-if="operations.length === 0" :class="$style.emptyStack" data-test="empty-stack">
				<span>{{ t('sheetmusic', 'No operations stacked. Click "+ Add Operation" below or select a preset.') }}</span>
			</div>
		</div>

		<!-- Add Operation Buttons -->
		<div :class="$style.addOpBar">
			<span :class="$style.subLabel">{{ t('sheetmusic', 'Add Step:') }}</span>
			<NcButton size="small" variant="secondary" data-test="add-gaussian" @click="emit('addOperation', 'gaussian')">
				<template #icon><Plus :size="16" /></template>
				{{ t('sheetmusic', 'Gaussian Filter') }}
			</NcButton>
			<NcButton size="small" variant="secondary" data-test="add-curves" @click="emit('addOperation', 'grayLevelMapping')">
				<template #icon><Plus :size="16" /></template>
				{{ t('sheetmusic', 'Curves / Levels') }}
			</NcButton>
			<NcButton size="small" variant="secondary" data-test="add-threshold" @click="emit('addOperation', 'adaptiveThreshold')">
				<template #icon><Plus :size="16" /></template>
				{{ t('sheetmusic', 'Adaptive Threshold') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.stackPane {
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	overflow: hidden;
}

.stackHeader {
	display: flex;
	flex-direction: column;
	gap: 6px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.stackTitle {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: 700;
	font-size: 14px;
}

.presetBar {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.subLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-right: 4px;
}

.presetChip {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 2px 8px;
	font-size: 11px;
	cursor: pointer;
	color: var(--color-main-text);
}

.presetChip:hover {
	background: var(--color-primary-light);
	color: var(--color-primary);
}

.operationsList {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-right: 4px;
}

.emptyStack {
	padding: 24px 12px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.addOpBar {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}
</style>
