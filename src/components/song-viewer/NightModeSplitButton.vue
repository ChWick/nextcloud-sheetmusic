<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import WeatherNightIcon from 'vue-material-design-icons/WeatherNight.vue'
import ViewportPopover from '../ViewportPopover.vue'
import ScoreAppearanceControls from './ScoreAppearanceControls.vue'
import {
	isNightScoreMode,
	SCORE_BRIGHTNESS_MAX,
	SCORE_SEPIA_MIN,
	type ScoreColorMode,
} from '../../utils/performance-settings'

const props = withDefaults(defineProps<{
	scoreColorMode?: ScoreColorMode
	scoreBrightness?: number
	scoreSepia?: number
	size?: 'small' | 'normal'
	toggleTestId?: string
}>(), {
	scoreColorMode: 'normal',
	scoreBrightness: SCORE_BRIGHTNESS_MAX,
	scoreSepia: SCORE_SEPIA_MIN,
	size: 'normal',
	toggleTestId: 'night-mode-toggle',
})

const emit = defineEmits<{
	'toggle-night-mode': []
	'update:scoreColorMode': [mode: ScoreColorMode]
	'update:scoreBrightness': [brightness: number]
	'update:scoreSepia': [sepia: number]
	'update:open': [open: boolean]
}>()

const isOpen = ref(false)
const isActive = computed(() =>
	isNightScoreMode(props.scoreColorMode)
	|| props.scoreSepia > 0
	|| props.scoreBrightness < SCORE_BRIGHTNESS_MAX,
)
const iconSize = computed(() => (props.size === 'small' ? 18 : 20))

let longPressTimer: ReturnType<typeof setTimeout> | null = null
let longPressTriggered = false

function clearLongPress() {
	if (longPressTimer !== null) {
		clearTimeout(longPressTimer)
		longPressTimer = null
	}
}

function onTogglePointerDown() {
	longPressTriggered = false
	clearLongPress()
	longPressTimer = setTimeout(() => {
		longPressTriggered = true
		longPressTimer = null
		isOpen.value = true
	}, 450)
}

function onToggleClick() {
	clearLongPress()
	if (longPressTriggered) {
		longPressTriggered = false
		return
	}
	emit('toggle-night-mode')
}

watch(isOpen, (open) => {
	emit('update:open', open)
})

onUnmounted(() => {
	clearLongPress()
})
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		:title="t('sheetmusic', 'Score appearance')"
		pane-test-id="score-appearance-popover"
		:pane-class="$style.popoverPane"
		:gap="6">
		<template #trigger="{ toggle }">
			<div :class="$style.splitGroup">
				<NcButton
					:variant="isActive ? 'primary' : 'secondary'"
					:size="size"
					:class="$style.mainBtn"
					:aria-pressed="isNightScoreMode(scoreColorMode)"
					:aria-label="t('sheetmusic', 'Night score mode')"
					:title="t('sheetmusic', 'Toggle night score inversion (hold for appearance options)')"
					:data-test="toggleTestId"
					@pointerdown="onTogglePointerDown"
					@pointerup="clearLongPress"
					@pointercancel="clearLongPress"
					@pointerleave="clearLongPress"
					@click="onToggleClick">
					<template #icon>
						<WeatherNightIcon :size="iconSize" />
					</template>
				</NcButton>
				<NcButton
					:variant="isOpen || isActive ? 'primary' : 'secondary'"
					:size="size"
					:class="$style.arrowBtn"
					:aria-expanded="isOpen"
					:aria-label="t('sheetmusic', 'Score appearance options')"
					:title="t('sheetmusic', 'Score appearance options')"
					:data-test="`${toggleTestId}-menu`"
					@click="toggle">
					<template #icon>
						<ChevronDownIcon :size="16" />
					</template>
				</NcButton>
			</div>
		</template>

		<ScoreAppearanceControls
			:score-color-mode="scoreColorMode"
			:score-brightness="scoreBrightness"
			:score-sepia="scoreSepia"
			test-id-prefix="popover-score"
			@update:score-color-mode="emit('update:scoreColorMode', $event)"
			@update:score-brightness="emit('update:scoreBrightness', $event)"
			@update:score-sepia="emit('update:scoreSepia', $event)" />
	</ViewportPopover>
</template>

<style module>
.splitGroup {
	display: inline-flex;
	align-items: center;
}

.mainBtn {
	border-top-right-radius: 0 !important;
	border-bottom-right-radius: 0 !important;
	margin-right: 0 !important;
}

.arrowBtn {
	border-top-left-radius: 0 !important;
	border-bottom-left-radius: 0 !important;
	margin-left: 1px !important;
	min-width: 24px !important;
	padding-left: 2px !important;
	padding-right: 2px !important;
}

.popoverPane {
	width: 280px;
}
</style>
