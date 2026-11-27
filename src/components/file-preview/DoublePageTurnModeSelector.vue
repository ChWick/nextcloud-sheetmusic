<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ViewportPopover from '../ViewportPopover.vue'
import type { DoublePageTurnMode } from '../../utils/page-turn'

const props = defineProps<{
	modelValue: DoublePageTurnMode
}>()

const emit = defineEmits<{
	'update:modelValue': [mode: DoublePageTurnMode]
	'update:open': [open: boolean]
}>()

interface TurnModeOption {
	mode: DoublePageTurnMode
	leftNum: string
	rightNum: string
	highlightLeft?: boolean
	shortLabel: string
	label: string
}

const options: TurnModeOption[] = [
	{
		mode: 'half',
		leftNum: '3',
		rightNum: '2',
		highlightLeft: true,
		shortLabel: t('sheetmusic', 'Half-page turn'),
		label: t('sheetmusic', 'Half-page turn (1/2 → 3/2 → 3/4)'),
	},
	{
		mode: 'step',
		leftNum: '2',
		rightNum: '3',
		shortLabel: t('sheetmusic', '1-page step'),
		label: t('sheetmusic', '1-page step (1/2 → 2/3 → 3/4)'),
	},
	{
		mode: 'spread',
		leftNum: '3',
		rightNum: '4',
		shortLabel: t('sheetmusic', '2-page jump'),
		label: t('sheetmusic', '2-page jump (1/2 → 3/4)'),
	},
]

const isOpen = ref(false)
const activeOption = computed(() => options.find((opt) => opt.mode === props.modelValue) ?? options[0])

watch(isOpen, (open) => {
	emit('update:open', open)
})

function selectMode(mode: DoublePageTurnMode) {
	emit('update:modelValue', mode)
	isOpen.value = false
}

function close() {
	isOpen.value = false
}

defineExpose({ close })
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		pane-test-id="double-page-turn-mode-menu"
		:pane-class="$style.menuPane"
		:gap="6">
		<template #trigger="{ toggle }">
			<NcButton
				variant="secondary"
				:class="$style.triggerBtn"
				:title="activeOption.label"
				:aria-label="activeOption.label"
				:aria-expanded="isOpen"
				aria-haspopup="menu"
				data-test="double-page-turn-mode-trigger"
				:data-active-turn-mode="activeOption.mode"
				@click="toggle">
				<span :class="$style.triggerContent">
					<svg
						:class="$style.icon"
						viewBox="0 0 74 22"
						width="78"
						height="24"
						aria-hidden="true">
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
						<path
							d="M31.5 11 H41.5 M38 7.5 L41.5 11 L38 14.5"
							fill="none"
							stroke="currentColor"
							stroke-width="1.6"
							stroke-linecap="round"
							stroke-linejoin="round" />
						<rect
							x="44.5"
							y="2"
							width="13"
							height="18"
							rx="1.8"
							:fill="activeOption.highlightLeft ? 'currentColor' : 'none'"
							:fill-opacity="activeOption.highlightLeft ? 0.18 : 0"
							stroke="currentColor"
							stroke-width="1.5" />
						<text
							x="51"
							y="14.5"
							text-anchor="middle"
							font-size="10"
							font-weight="700"
							fill="currentColor">{{ activeOption.leftNum }}</text>
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
							fill="currentColor">{{ activeOption.rightNum }}</text>
					</svg>
					<ChevronDownIcon :size="18" :class="[$style.chevron, isOpen && $style.chevronOpen]" />
				</span>
			</NcButton>
		</template>

		<div :class="$style.menuList" role="menu" :aria-label="t('sheetmusic', 'Page turning mode')">
			<button
				v-for="opt in options"
				:key="opt.mode"
				type="button"
				role="menuitemradio"
				:class="[$style.menuItem, modelValue === opt.mode && $style.menuItemActive]"
				:title="opt.label"
				:aria-label="opt.label"
				:aria-checked="modelValue === opt.mode"
				:data-turn-mode="opt.mode"
				@click="selectMode(opt.mode)">
				<svg
					:class="$style.icon"
					viewBox="0 0 74 22"
					width="78"
					height="24"
					aria-hidden="true">
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
					<path
						d="M31.5 11 H41.5 M38 7.5 L41.5 11 L38 14.5"
						fill="none"
						stroke="currentColor"
						stroke-width="1.6"
						stroke-linecap="round"
						stroke-linejoin="round" />
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
				<span :class="$style.menuLabel">{{ opt.shortLabel }}</span>
			</button>
		</div>
	</ViewportPopover>
</template>

<style module>
.triggerBtn {
	min-width: auto !important;
	padding-left: 8px !important;
	padding-right: 6px !important;
}

.triggerContent {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.chevron {
	display: inline-flex;
	transition: transform 0.15s ease;
}

.chevronOpen {
	transform: rotate(180deg);
}

.icon {
	display: block;
	flex-shrink: 0;
}

.startSpread {
	opacity: 0.72;
}

.menuPane {
	padding: 6px;
	min-width: 220px;
}

.menuList {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

button[type='button'].menuItem {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	min-height: 38px;
	margin: 0;
	padding: 6px 10px;
	border: 1px solid transparent;
	border-radius: var(--border-radius, 4px);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.85rem;
	font-weight: 500;
	text-align: left;
	cursor: pointer;
	white-space: nowrap;
}

button[type='button'].menuItem:hover {
	background: var(--color-background-hover);
}

button[type='button'].menuItemActive {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #ffffff);
	font-weight: 600;
}

button[type='button'].menuItemActive:hover {
	background: var(--color-primary-element-hover, var(--color-primary-element));
}

.menuLabel {
	flex: 1;
}
</style>
