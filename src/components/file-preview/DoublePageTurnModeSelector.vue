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
			:class="$style.turnModeBtn"
			:variant="modelValue === opt.mode ? 'primary' : 'secondary'"
			:title="opt.label"
			:aria-label="opt.label"
			:aria-pressed="modelValue === opt.mode"
			:data-turn-mode="opt.mode"
			@click="emit('update:modelValue', opt.mode)">
			<svg
				:class="$style.icon"
				viewBox="0 0 74 22"
				width="78"
				height="24"
				aria-hidden="true">
				<!-- Starting spread: 1 / 2 -->
				<g :class="$style.startSpread">
					<rect
						x="1"
						y="2"
						width="13"
						height="18"
						rx="1.8"
						fill="none"
						stroke="currentColor"
						stroke-width="1.4" />
					<text
						x="7.5"
						y="14.5"
						text-anchor="middle"
						font-size="10"
						font-weight="600"
						fill="currentColor">1</text>
					<rect
						x="15.5"
						y="2"
						width="13"
						height="18"
						rx="1.8"
						fill="none"
						stroke="currentColor"
						stroke-width="1.4" />
					<text
						x="22"
						y="14.5"
						text-anchor="middle"
						font-size="10"
						font-weight="600"
						fill="currentColor">2</text>
				</g>

				<!-- Transition arrow -->
				<path
					d="M31.5 11 H41.5 M38 7.5 L41.5 11 L38 14.5"
					fill="none"
					stroke="currentColor"
					stroke-width="1.6"
					stroke-linecap="round"
					stroke-linejoin="round" />

				<!-- Resulting spread -->
				<rect
					x="44.5"
					y="2"
					width="13"
					height="18"
					rx="1.8"
					:fill="opt.highlightLeft ? 'currentColor' : 'none'"
					:fill-opacity="opt.highlightLeft ? 0.18 : 0"
					stroke="currentColor"
					stroke-width="1.5" />
				<text
					x="51"
					y="14.5"
					text-anchor="middle"
					font-size="10"
					font-weight="700"
					fill="currentColor">{{ opt.leftNum }}</text>
				<rect
					x="59"
					y="2"
					width="13"
					height="18"
					rx="1.8"
					fill="none"
					stroke="currentColor"
					stroke-width="1.5" />
				<text
					x="65.5"
					y="14.5"
					text-anchor="middle"
					font-size="10"
					font-weight="700"
					fill="currentColor">{{ opt.rightNum }}</text>
			</svg>
		</NcButton>
	</div>
</template>

<style module>
.segmentedGroup {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.turnModeBtn {
	min-width: auto !important;
	padding-left: 8px !important;
	padding-right: 8px !important;
}

.icon {
	display: block;
}

.startSpread {
	opacity: 0.72;
}
</style>
