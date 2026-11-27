<script setup lang="ts">
import { computed } from 'vue'

export type TuningStatus = 'idle' | 'in-tune' | 'close' | 'off'

const props = defineProps<{
	cents: number | null
	status: TuningStatus
}>()

const needlePercent = computed(() => {
	if (props.cents === null) return 50
	return Math.max(0, Math.min(100, 50 + props.cents))
})
</script>

<template>
	<div :class="$style.meterWrap" aria-hidden="true">
		<div :class="$style.meterTrack">
			<div :class="$style.meterCenterZone" />
			<div :class="$style.meterCenterTick" />
			<div
				v-if="cents !== null"
				:class="[
					$style.meterNeedle,
					status === 'in-tune' && $style.needleInTune,
					status === 'close' && $style.needleClose,
					status === 'off' && $style.needleOff,
				]"
				:style="{ left: `${needlePercent}%` }"
				data-test="tuner-needle" />
		</div>
		<div :class="$style.meterScale">
			<span>♭ -50¢</span>
			<span>0¢</span>
			<span>+50¢ ♯</span>
		</div>
	</div>
</template>

<style module>
.meterWrap {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.meterTrack {
	position: relative;
	height: 16px;
	border-radius: 999px;
	background: var(--color-background-dark, #e5e5e5);
	overflow: hidden;
}

.meterCenterZone {
	position: absolute;
	top: 0;
	bottom: 0;
	left: 45%;
	width: 10%;
	background: rgba(46, 125, 50, 0.22);
}

.meterCenterTick {
	position: absolute;
	top: 0;
	bottom: 0;
	left: 50%;
	width: 2px;
	transform: translateX(-50%);
	background: var(--color-border-dark, #888);
}

.meterNeedle {
	position: absolute;
	top: 1px;
	bottom: 1px;
	width: 6px;
	border-radius: 999px;
	transform: translateX(-50%);
	transition: left 0.12s ease-out;
}

.needleInTune {
	background: var(--color-success, #2e7d32);
}

.needleClose {
	background: var(--color-warning, #ed6c02);
}

.needleOff {
	background: var(--color-error, #d32f2f);
}

.meterScale {
	display: flex;
	justify-content: space-between;
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
