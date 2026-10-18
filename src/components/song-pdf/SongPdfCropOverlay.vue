<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import type { PageCrop } from './crop-types'

const props = defineProps<{
	cropBox: PageCrop
	canvasWidth: number
	canvasHeight: number
	isCustomCropped: boolean
}>()

const emit = defineEmits<{
	'update:cropBox': [crop: PageCrop]
}>()

const overlayRef = ref<HTMLDivElement>()

type DragHandle = 'move' | 'nw' | 'ne' | 'se' | 'sw' | 'n' | 'e' | 's' | 'w' | 'create' | null
const activeDrag = ref<DragHandle>(null)
let startX = 0
let startY = 0
let startBox: PageCrop = { left: 0, top: 0, width: 1, height: 1 }

const overlayBoxStyle = computed(() => {
	const w = props.canvasWidth
	const h = props.canvasHeight
	const left = Math.round(props.cropBox.left * w)
	const top = Math.round(props.cropBox.top * h)
	const width = Math.round(props.cropBox.width * w)
	const height = Math.round(props.cropBox.height * h)

	return {
		left: `${left}px`,
		top: `${top}px`,
		width: `${width}px`,
		height: `${height}px`,
	}
})

function handlePointerDown(handle: DragHandle, e: PointerEvent) {
	e.preventDefault()
	e.stopPropagation()
	if (!overlayRef.value) return

	activeDrag.value = handle
	const rect = overlayRef.value.getBoundingClientRect()
	startX = (e.clientX - rect.left) / rect.width
	startY = (e.clientY - rect.top) / rect.height
	startBox = { ...props.cropBox }

	if (handle === 'create') {
		emit('update:cropBox', {
			left: Math.max(0, Math.min(1, startX)),
			top: Math.max(0, Math.min(1, startY)),
			width: 0,
			height: 0,
		})
	}

	window.addEventListener('pointermove', onPointerMove)
	window.addEventListener('pointerup', onPointerUp)
}

function onPointerMove(e: PointerEvent) {
	if (!activeDrag.value || !overlayRef.value) return
	const rect = overlayRef.value.getBoundingClientRect()
	const curX = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
	const curY = Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height))
	const dx = curX - startX
	const dy = curY - startY

	const minDim = 0.05 // Minimum 5% width & height

	if (activeDrag.value === 'create') {
		const left = Math.min(startX, curX)
		const top = Math.min(startY, curY)
		const width = Math.max(minDim, Math.abs(curX - startX))
		const height = Math.max(minDim, Math.abs(curY - startY))
		emit('update:cropBox', {
			left: Math.max(0, Math.min(1 - minDim, left)),
			top: Math.max(0, Math.min(1 - minDim, top)),
			width: Math.min(1 - left, width),
			height: Math.min(1 - top, height),
		})
		return
	}

	if (activeDrag.value === 'move') {
		let newLeft = startBox.left + dx
		let newTop = startBox.top + dy

		if (newLeft < 0) newLeft = 0
		if (newTop < 0) newTop = 0
		if (newLeft + startBox.width > 1) newLeft = 1 - startBox.width
		if (newTop + startBox.height > 1) newTop = 1 - startBox.height

		emit('update:cropBox', {
			...props.cropBox,
			left: newLeft,
			top: newTop,
		})
		return
	}

	let { left, top, width, height } = startBox
	const right = left + width
	const bottom = top + height

	if (activeDrag.value.includes('w')) {
		left = Math.min(right - minDim, Math.max(0, startBox.left + dx))
		width = right - left
	}
	if (activeDrag.value.includes('e')) {
		const newRight = Math.max(left + minDim, Math.min(1, right + dx))
		width = newRight - left
	}
	if (activeDrag.value.includes('n')) {
		top = Math.min(bottom - minDim, Math.max(0, startBox.top + dy))
		height = bottom - top
	}
	if (activeDrag.value.includes('s')) {
		const newBottom = Math.max(top + minDim, Math.min(1, bottom + dy))
		height = newBottom - top
	}

	emit('update:cropBox', { left, top, width, height })
}

function onPointerUp() {
	activeDrag.value = null
	window.removeEventListener('pointermove', onPointerMove)
	window.removeEventListener('pointerup', onPointerUp)

	// Ensure sanitized box
	const b = props.cropBox
	emit('update:cropBox', {
		left: Math.max(0, Math.min(0.95, b.left)),
		top: Math.max(0, Math.min(0.95, b.top)),
		width: Math.max(0.05, Math.min(1 - b.left, b.width)),
		height: Math.max(0.05, Math.min(1 - b.top, b.height)),
	})
}

onUnmounted(() => {
	window.removeEventListener('pointermove', onPointerMove)
	window.removeEventListener('pointerup', onPointerUp)
})
</script>

<template>
	<div
		ref="overlayRef"
		:class="$style.interactiveOverlay"
		data-test="crop-overlay"
		@pointerdown="handlePointerDown('create', $event)">
		<!-- Darkened Backdrop Mask around the active crop area -->
		<!-- Top shade -->
		<div
			:class="$style.shade"
			:style="{ top: 0, left: 0, right: 0, height: `${cropBox.top * 100}%` }" />
		<!-- Bottom shade -->
		<div
			:class="$style.shade"
			:style="{ top: `${(cropBox.top + cropBox.height) * 100}%`, left: 0, right: 0, bottom: 0 }" />
		<!-- Left shade -->
		<div
			:class="$style.shade"
			:style="{
				top: `${cropBox.top * 100}%`,
				left: 0,
				width: `${cropBox.left * 100}%`,
				height: `${cropBox.height * 100}%`
			}" />
		<!-- Right shade -->
		<div
			:class="$style.shade"
			:style="{
				top: `${cropBox.top * 100}%`,
				left: `${(cropBox.left + cropBox.width) * 100}%`,
				right: 0,
				height: `${cropBox.height * 100}%`
			}" />

		<!-- The Interactive Crop Box -->
		<div
			:class="[$style.cropBox, isCustomCropped && $style.cropBoxActive]"
			:style="overlayBoxStyle"
			data-test="crop-box"
			@pointerdown.stop="handlePointerDown(isCustomCropped ? 'move' : 'create', $event)">
			<!-- Crop Grid Guidelines (Rule of thirds) -->
			<div :class="$style.gridH1" />
			<div :class="$style.gridH2" />
			<div :class="$style.gridV1" />
			<div :class="$style.gridV2" />

			<!-- Resizing Corner Handles -->
			<div
				:class="[$style.handle, $style.handleNw]"
				data-test="handle-nw"
				@pointerdown.stop="handlePointerDown('nw', $event)" />
			<div
				:class="[$style.handle, $style.handleNe]"
				data-test="handle-ne"
				@pointerdown.stop="handlePointerDown('ne', $event)" />
			<div
				:class="[$style.handle, $style.handleSe]"
				data-test="handle-se"
				@pointerdown.stop="handlePointerDown('se', $event)" />
			<div
				:class="[$style.handle, $style.handleSw]"
				data-test="handle-sw"
				@pointerdown.stop="handlePointerDown('sw', $event)" />

			<!-- Resizing Edge Handles -->
			<div
				:class="[$style.handle, $style.handleN]"
				data-test="handle-n"
				@pointerdown.stop="handlePointerDown('n', $event)" />
			<div
				:class="[$style.handle, $style.handleE]"
				data-test="handle-e"
				@pointerdown.stop="handlePointerDown('e', $event)" />
			<div
				:class="[$style.handle, $style.handleS]"
				data-test="handle-s"
				@pointerdown.stop="handlePointerDown('s', $event)" />
			<div
				:class="[$style.handle, $style.handleW]"
				data-test="handle-w"
				@pointerdown.stop="handlePointerDown('w', $event)" />
		</div>
	</div>
</template>

<style module>
.interactiveOverlay {
	position: absolute;
	inset: 0;
	cursor: crosshair;
}

.shade {
	position: absolute;
	background-color: rgba(0, 0, 0, 0.55);
	pointer-events: none;
}

.cropBox {
	position: absolute;
	box-sizing: border-box;
	border: 2px dashed #ffffff;
	box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.5);
	cursor: move;
}

.cropBoxActive {
	border: 2px solid var(--color-primary-element, #0082c9);
}

.gridH1,
.gridH2,
.gridV1,
.gridV2 {
	position: absolute;
	pointer-events: none;
	border-color: rgba(255, 255, 255, 0.25);
}

.gridH1 {
	top: 33.33%;
	left: 0;
	right: 0;
	border-top: 1px dashed rgba(255, 255, 255, 0.35);
}

.gridH2 {
	top: 66.66%;
	left: 0;
	right: 0;
	border-top: 1px dashed rgba(255, 255, 255, 0.35);
}

.gridV1 {
	left: 33.33%;
	top: 0;
	bottom: 0;
	border-left: 1px dashed rgba(255, 255, 255, 0.35);
}

.gridV2 {
	left: 66.66%;
	top: 0;
	bottom: 0;
	border-left: 1px dashed rgba(255, 255, 255, 0.35);
}

/* Corner Handles */
.handle {
	position: absolute;
	width: 14px;
	height: 14px;
	background-color: var(--color-primary-element, #0082c9);
	border: 2px solid #ffffff;
	border-radius: 2px;
	box-sizing: border-box;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
}

.handleNw {
	top: -7px;
	left: -7px;
	cursor: nwse-resize;
}

.handleNe {
	top: -7px;
	right: -7px;
	cursor: nesw-resize;
}

.handleSe {
	bottom: -7px;
	right: -7px;
	cursor: nwse-resize;
}

.handleSw {
	bottom: -7px;
	left: -7px;
	cursor: nesw-resize;
}

/* Edge handles */
.handleN {
	top: -7px;
	left: calc(50% - 7px);
	cursor: ns-resize;
}

.handleS {
	bottom: -7px;
	left: calc(50% - 7px);
	cursor: ns-resize;
}

.handleW {
	left: -7px;
	top: calc(50% - 7px);
	cursor: ew-resize;
}

.handleE {
	right: -7px;
	top: calc(50% - 7px);
	cursor: ew-resize;
}
</style>
