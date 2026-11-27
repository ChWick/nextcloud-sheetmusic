<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import WeatherNightIcon from 'vue-material-design-icons/WeatherNight.vue'
import SplitPopoverButton from '../SplitPopoverButton.vue'
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
	open?: boolean
	size?: 'small' | 'normal'
	toggleTestId?: string
}>(), {
	scoreColorMode: 'normal',
	scoreBrightness: SCORE_BRIGHTNESS_MAX,
	scoreSepia: SCORE_SEPIA_MIN,
	open: undefined,
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

const isActive = computed(() =>
	isNightScoreMode(props.scoreColorMode)
	|| props.scoreSepia > 0
	|| props.scoreBrightness < SCORE_BRIGHTNESS_MAX,
)
const iconSize = computed(() => (props.size === 'small' ? 18 : 20))
</script>

<template>
	<SplitPopoverButton
		:open="open"
		:popover-title="t('sheetmusic', 'Score appearance')"
		popover-test-id="score-appearance-popover"
		:active="isActive"
		:pressed="isNightScoreMode(scoreColorMode)"
		:size="size"
		:main-label="t('sheetmusic', 'Night score mode')"
		:main-title="t('sheetmusic', 'Toggle night score inversion (hold for appearance options)')"
		:main-test-id="toggleTestId"
		:menu-label="t('sheetmusic', 'Score appearance options')"
		:menu-test-id="`${toggleTestId}-menu`"
		:long-press-ms="450"
		@click="emit('toggle-night-mode')"
		@update:open="emit('update:open', $event)">
		<template #icon>
			<WeatherNightIcon :size="iconSize" />
		</template>

		<ScoreAppearanceControls
			:score-color-mode="scoreColorMode"
			:score-brightness="scoreBrightness"
			:score-sepia="scoreSepia"
			test-id-prefix="popover-score"
			@update:score-color-mode="emit('update:scoreColorMode', $event)"
			@update:score-brightness="emit('update:scoreBrightness', $event)"
			@update:score-sepia="emit('update:scoreSepia', $event)" />
	</SplitPopoverButton>
</template>
