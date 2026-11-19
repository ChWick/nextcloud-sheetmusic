<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { DoublePageTurnMode } from '../../utils/page-turn'

defineProps<{
	modelValue: DoublePageTurnMode
}>()

const emit = defineEmits<{
	'update:modelValue': [mode: DoublePageTurnMode]
}>()

interface TurnModeOption {
	mode: DoublePageTurnMode
	leftNum: string
	rightNum: string
	highlightLeft?: boolean
	label: string
}

const options: TurnModeOption[] = [
	{
		mode: 'half',
		leftNum: '3',
		rightNum: '2',
		highlightLeft: true,
		label: t('sheetmusic', 'Half-page turn (1/2 → 3/2 → 3/4)'),
	},
	{
		mode: 'step',
		leftNum: '2',
		rightNum: '3',
		label: t('sheetmusic', '1-page step (1/2 → 2/3 → 3/4)'),
	},
	{
		mode: 'spread',
		leftNum: '3',
		rightNum: '4',
		label: t('sheetmusic', '2-page jump (1/2 → 3/4)'),
	},
]
</script>

<template>
	<div :class="$style.segmentedGroup" role="group" :aria-label="t('sheetmusic', 'Page turning mode')">
		<NcButton
			v-for="opt in options"
			:key="opt.mode"
			:variant="modelValue === opt.mode ? 'primary' : 'secondary'"
			:title="opt.label"
			:aria-label="opt.label"
			:aria-pressed="modelValue === opt.mode"
			:data-turn-mode="opt.mode"
			@click="emit('update:modelValue', opt.mode)">
			<template #icon>
				<svg
					:class="$style.icon"
					viewBox="0 0 34 22"
					width="30"
					height="20"
					aria-hidden="true">
					<!-- Left mini page -->
					<rect
						x="1.5"
						y="2"
						width="14"
						height="18"
						rx="2"
						:fill="opt.highlightLeft ? 'currentColor' : 'none'"
						:fill-opacity="opt.highlightLeft ? 0.18 : 0"
						stroke="currentColor"
						stroke-width="1.5" />
					<text
						x="8.5"
						y="14.5"
						text-anchor="middle"
						font-size="10"
						font-weight="700"
						fill="currentColor">{{ opt.leftNum }}</text>
					<!-- Right mini page -->
					<rect
						x="18.5"
						y="2"
						width="14"
						height="18"
						rx="2"
						fill="none"
						stroke="currentColor"
						stroke-width="1.5" />
					<text
						x="25.5"
						y="14.5"
						text-anchor="middle"
						font-size="10"
						font-weight="700"
						fill="currentColor">{{ opt.rightNum }}</text>
				</svg>
			</template>
		</NcButton>
	</div>
</template>

<style module>
.segmentedGroup {
	display: inline-flex;
	align-items: center;
	gap: 2px;
}

.icon {
	display: block;
}
</style>
