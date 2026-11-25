<script setup lang="ts">
import { computed } from 'vue'
import { getMusicFontFamily, type MusicFontId } from '../../utils/musicFont'
import { getStampDefinition, isFingeringDigit } from '../../utils/musicSymbols'

const props = withDefaults(
	defineProps<{
		symbol: string
		musicFont?: MusicFontId
		width?: number
		height?: number
		baseFontSize?: number
	}>(),
	{
		musicFont: 'bravura',
		width: 24,
		height: 24,
		baseFontSize: 18,
	},
)

const stampDef = computed(() => getStampDefinition(props.symbol))
const fontFamily = computed(() => getMusicFontFamily(props.musicFont))
const effectiveFontSize = computed(() =>
	isFingeringDigit(props.symbol)
		? Math.round(props.baseFontSize * 1.65)
		: props.baseFontSize,
)
const textX = computed(() =>
	props.width / 2 + (stampDef.value?.inkOffset.ox ?? 0) * effectiveFontSize.value,
)
const textY = computed(() =>
	props.height / 2 + (stampDef.value?.inkOffset.oy ?? 0) * effectiveFontSize.value,
)
</script>

<template>
	<svg
		v-if="stampDef"
		:width="width"
		:height="height"
		:viewBox="`0 0 ${width} ${height}`"
		aria-hidden="true">
		<text
			:x="textX"
			:y="textY"
			:font-size="effectiveFontSize"
			text-anchor="middle"
			dominant-baseline="alphabetic"
			fill="currentColor"
			:font-family="fontFamily">
			{{ symbol }}
		</text>
	</svg>
	<span
		v-else
		:class="$style.fallback"
		:style="{ fontFamily }">
		{{ symbol }}
	</span>
</template>

<style module>
.fallback {
	font-size: 1rem;
	font-weight: 700;
	line-height: 1;
}
</style>
