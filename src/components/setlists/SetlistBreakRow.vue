<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CoffeeOutlineIcon from 'vue-material-design-icons/CoffeeOutline.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import {
	clampBreakMinutes,
	formatStageDuration,
	MAX_BREAK_MINUTES,
	MIN_BREAK_MINUTES,
	type SetlistBreak,
} from '../../utils/setlist-timing'

const props = withDefaults(defineProps<{
	breakItem: SetlistBreak
	segmentNumber: number
	segmentDurationSeconds?: number
	colSpan: number
	canWrite?: boolean
}>(), {
	segmentDurationSeconds: 0,
	canWrite: true,
})

const emit = defineEmits<{
	update: [updated: SetlistBreak]
	remove: [afterIndex: number]
}>()

function onLabelChange(event: Event) {
	const raw = (event.target as HTMLInputElement).value.trim()
	emit('update', {
		...props.breakItem,
		label: raw || t('sheetmusic', 'Intermission'),
	})
}

function onMinutesChange(event: Event) {
	const parsed = Number.parseInt((event.target as HTMLInputElement).value, 10)
	emit('update', {
		...props.breakItem,
		durationMinutes: clampBreakMinutes(parsed),
	})
}
</script>

<template>
	<tr :class="$style.breakRow" :data-test="`setlist-break-row-${breakItem.afterIndex}`">
		<td :colspan="colSpan" :class="$style.breakCell">
			<div :class="$style.breakInner">
				<span :class="$style.breakBadge">
					<CoffeeOutlineIcon :size="16" />
					<input
						v-if="canWrite !== false"
						type="text"
						:value="breakItem.label"
						:class="$style.labelInput"
						:aria-label="t('sheetmusic', 'Break label')"
						:data-test="`setlist-break-label-${breakItem.afterIndex}`"
						@change="onLabelChange">
					<strong v-else>{{ breakItem.label }}</strong>
				</span>

				<span v-if="segmentDurationSeconds > 0" :class="$style.segmentMeta" data-test="setlist-segment-subtotal">
					{{ t('sheetmusic', 'Set {num}: {dur}', { num: segmentNumber, dur: formatStageDuration(segmentDurationSeconds) }, { escape: false }) }}
				</span>

				<label :class="$style.durationWrap">
					<template v-if="canWrite !== false">
						<input
							type="number"
							:value="breakItem.durationMinutes"
							:min="MIN_BREAK_MINUTES"
							:max="MAX_BREAK_MINUTES"
							:class="$style.minutesInput"
							:aria-label="t('sheetmusic', 'Break duration in minutes')"
							:data-test="`setlist-break-minutes-${breakItem.afterIndex}`"
							@change="onMinutesChange">
						<span>{{ t('sheetmusic', 'min') }}</span>
					</template>
					<span v-else>{{ breakItem.durationMinutes }} {{ t('sheetmusic', 'min') }}</span>
				</label>

				<NcButton
					v-if="canWrite !== false"
					variant="tertiary"
					size="small"
					:title="t('sheetmusic', 'Remove break')"
					:aria-label="t('sheetmusic', 'Remove break')"
					:data-test="`setlist-break-remove-${breakItem.afterIndex}`"
					@click="emit('remove', breakItem.afterIndex)">
					<template #icon><DeleteIcon :size="16" /></template>
				</NcButton>
			</div>
		</td>
	</tr>
</template>

<style module>
.breakRow {
	background: var(--color-background-hover);
}

.breakCell {
	padding: 8px 12px;
	border-top: 1px dashed var(--color-border-dark, var(--color-border));
	border-bottom: 1px dashed var(--color-border-dark, var(--color-border));
}

.breakInner {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.breakBadge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-primary-element);
	font-weight: 600;
}

.labelInput {
	width: 140px;
	height: 28px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	font-weight: 600;
}

.segmentMeta {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin-right: auto;
	font-variant-numeric: tabular-nums;
}

.durationWrap {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.minutesInput {
	width: 60px;
	height: 28px;
	padding: 0 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	font-variant-numeric: tabular-nums;
	text-align: right;
}
</style>
