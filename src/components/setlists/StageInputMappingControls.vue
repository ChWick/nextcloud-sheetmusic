<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import {
	DEFAULT_STAGE_INPUT_MAPPING,
	type PedalSlotKey,
	type StageAction,
	type StageInputMapping,
	type TapZoneLayout,
} from '../../utils/stage-input-mapping'

const props = defineProps<{
	mapping: StageInputMapping
}>()

const emit = defineEmits<{
	'update:mapping': [mapping: StageInputMapping]
}>()

interface ActionOption {
	value: StageAction
	label: string
}

const tapLayouts: Array<{ id: TapZoneLayout; label: () => string }> = [
	{ id: 'left-right', label: () => t('sheetmusic', 'Left / Right') },
	{ id: 'top-bottom', label: () => t('sheetmusic', 'Top / Bottom') },
	{ id: 'right-wide', label: () => t('sheetmusic', 'Wide Right') },
	{ id: 'disabled', label: () => t('sheetmusic', 'Tap Off') },
]

const pedalSlots: Array<{ key: PedalSlotKey; label: () => string }> = [
	{ key: 'pedalNext', label: () => t('sheetmusic', 'Right / PageDown (Pedal 1)') },
	{ key: 'pedalPrev', label: () => t('sheetmusic', 'Left / PageUp (Pedal 2)') },
	{ key: 'pedalAux1', label: () => t('sheetmusic', 'Up Arrow (Pedal 3)') },
	{ key: 'pedalAux2', label: () => t('sheetmusic', 'Down Arrow (Pedal 4)') },
	{ key: 'keySpace', label: () => t('sheetmusic', 'Spacebar') },
	{ key: 'keyEnter', label: () => t('sheetmusic', 'Enter') },
]

const actionOptions = computed<ActionOption[]>(() => [
	{ value: 'autoscroll-or-next-page', label: t('sheetmusic', 'Auto-scroll (scroll) / Next page') },
	{ value: 'next-page', label: t('sheetmusic', 'Next page') },
	{ value: 'prev-page', label: t('sheetmusic', 'Previous page') },
	{ value: 'next-song', label: t('sheetmusic', 'Next song') },
	{ value: 'prev-song', label: t('sheetmusic', 'Previous song') },
	{ value: 'toggle-hud', label: t('sheetmusic', 'Show / hide toolbar') },
	{ value: 'toggle-metronome', label: t('sheetmusic', 'Toggle metronome') },
	{ value: 'toggle-autoscroll', label: t('sheetmusic', 'Toggle auto-scroll') },
	{ value: 'toggle-night-mode', label: t('sheetmusic', 'Toggle night mode') },
	{ value: 'toggle-annotations', label: t('sheetmusic', 'Toggle annotations') },
	{ value: 'none', label: t('sheetmusic', 'Disabled') },
])

function getActionOption(action: StageAction): ActionOption {
	return actionOptions.value.find((opt) => opt.value === action) ?? actionOptions.value[actionOptions.value.length - 1]
}

function updateTapLayout(layout: TapZoneLayout) {
	emit('update:mapping', { ...props.mapping, tapZoneLayout: layout })
}

function updateSlot(slot: PedalSlotKey, option: ActionOption | null) {
	if (!option) return
	emit('update:mapping', { ...props.mapping, [slot]: option.value })
}

function resetDefaults() {
	emit('update:mapping', { ...DEFAULT_STAGE_INPUT_MAPPING })
}
</script>

<template>
	<div :class="$style.wrap" data-test="stage-input-mapping-controls">
		<div :class="$style.subGroup">
			<span :class="$style.subLabel">{{ t('sheetmusic', 'Screen tap zones') }}</span>
			<div :class="$style.layoutGrid">
				<button
					v-for="opt in tapLayouts"
					:key="opt.id"
					type="button"
					:class="[$style.layoutBtn, mapping.tapZoneLayout === opt.id && $style.layoutBtnActive]"
					:aria-pressed="mapping.tapZoneLayout === opt.id"
					:data-test="`tap-layout-${opt.id}`"
					@click="updateTapLayout(opt.id)">
					{{ opt.label() }}
				</button>
			</div>
		</div>

		<div :class="$style.subGroup">
			<div :class="$style.slotHeader">
				<span :class="$style.subLabel">{{ t('sheetmusic', 'Pedal & keyboard mapping') }}</span>
				<NcButton
					variant="tertiary"
					size="small"
					data-test="stage-input-reset-button"
					@click="resetDefaults">
					{{ t('sheetmusic', 'Reset') }}
				</NcButton>
			</div>
			<div :class="$style.slotList">
				<div
					v-for="slot in pedalSlots"
					:key="slot.key"
					:class="$style.slotRow"
					:data-test="`pedal-slot-${slot.key}`">
					<NcSelect
						:model-value="getActionOption(mapping[slot.key])"
						:options="actionOptions"
						label="label"
						:clearable="false"
						:input-label="slot.label()"
						:class="$style.slotSelect"
						@update:model-value="updateSlot(slot.key, $event)" />
				</div>
			</div>
		</div>
	</div>
</template>

<style module>
.wrap {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.subGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.subLabel {
	font-size: 0.78rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.layoutGrid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 6px;
}

button[type='button'].layoutBtn {
	margin: 0;
	padding: 6px 8px;
	min-height: 32px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.78rem;
	font-weight: 500;
	cursor: pointer;
}

button[type='button'].layoutBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	font-weight: 600;
}

.slotHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.slotList {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.slotRow {
	width: 100%;
}

.slotSelect {
	width: 100%;
}

.slotSelect :deep(.vs__selected),
.slotSelect :deep(.vs__search) {
	color: var(--color-main-text);
}

.slotSelect :deep(.vs__open-indicator) {
	fill: var(--color-main-text);
}
</style>
