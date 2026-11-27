<script setup lang="ts">
defineProps<{
	leftNum: string
	rightNum: string
	highlightLeft?: boolean
}>()
</script>

<template>
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
			:fill="highlightLeft ? 'currentColor' : 'none'"
			:fill-opacity="highlightLeft ? 0.18 : 0"
			stroke="currentColor"
			stroke-width="1.5" />
		<text
			x="51"
			y="14.5"
			text-anchor="middle"
			font-size="10"
			font-weight="700"
			fill="currentColor">{{ leftNum }}</text>
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
			fill="currentColor">{{ rightNum }}</text>
	</svg>
</template>

<style module>
.icon {
	display: block;
	flex-shrink: 0;
}

.startSpread {
	opacity: 0.72;
}
</style>
