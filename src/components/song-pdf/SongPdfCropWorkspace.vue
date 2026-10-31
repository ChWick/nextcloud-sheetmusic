<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import SongPdfCropOverlay from './SongPdfCropOverlay.vue'
import type { PageCrop } from './crop-types'

const props = withDefaults(
	defineProps<{
		canvasWidth: number
		canvasHeight: number
		zoomLevel?: number
		isLoadingPage?: boolean
		isCustomCropped?: boolean
		cropBox: PageCrop
	}>(),
	{
		zoomLevel: 1.0,
		isLoadingPage: false,
		isCustomCropped: false,
	},
)

const emit = defineEmits<{
	'update:cropBox': [crop: PageCrop]
	'zoom-change': [newZoom: number]
}>()

const workspaceRef = ref<HTMLDivElement>()
const canvasRef = ref<HTMLCanvasElement>()

// Scaled display dimensions
const displayWidth = computed(() => Math.round(props.canvasWidth * props.zoomLevel))
const displayHeight = computed(() => Math.round(props.canvasHeight * props.zoomLevel))

// Pan state
const isPanning = ref(false)
let isSpacePressed = false
let panStartX = 0
let panStartY = 0
let scrollStartLeft = 0
let scrollStartTop = 0

function handleKeyDown(e: KeyboardEvent) {
	if (e.code === 'Space' && !isSpacePressed) {
		const target = e.target as HTMLElement | null
		// Ignore if user is inside an input/button
		if (target && ['INPUT', 'TEXTAREA', 'BUTTON'].includes(target.tagName)) return
		isSpacePressed = true
	}
}

function handleKeyUp(e: KeyboardEvent) {
	if (e.code === 'Space') {
		isSpacePressed = false
		if (isPanning.value) {
			endPan()
		}
	}
}

function handleMouseDown(e: MouseEvent) {
	// Middle-click (button 1) OR spacebar + left click OR clicking on the workspace background
	const isBackdropClick = e.target === workspaceRef.value
	const isMiddleClick = e.button === 1
	const isSpaceDrag = isSpacePressed && e.button === 0

	if (isMiddleClick || isSpaceDrag || isBackdropClick) {
		if (!workspaceRef.value) return
		e.preventDefault()
		isPanning.value = true
		panStartX = e.clientX
		panStartY = e.clientY
		scrollStartLeft = workspaceRef.value.scrollLeft
		scrollStartTop = workspaceRef.value.scrollTop
		window.addEventListener('mousemove', onMouseMove)
		window.addEventListener('mouseup', endPan)
	}
}

function onMouseMove(e: MouseEvent) {
	if (!isPanning.value || !workspaceRef.value) return
	const dx = e.clientX - panStartX
	const dy = e.clientY - panStartY
	workspaceRef.value.scrollLeft = scrollStartLeft - dx
	workspaceRef.value.scrollTop = scrollStartTop - dy
}

function endPan() {
	isPanning.value = false
	window.removeEventListener('mousemove', onMouseMove)
	window.removeEventListener('mouseup', endPan)
}

function handleWheel(e: WheelEvent) {
	// Ctrl + Wheel or Cmd + Wheel to zoom
	if (e.ctrlKey || e.metaKey) {
		e.preventDefault()
		const factor = e.deltaY < 0 ? 1.15 : 1 / 1.15
		const newZoom = Math.min(3.0, Math.max(0.5, Math.round(props.zoomLevel * factor * 100) / 100))
		if (newZoom !== props.zoomLevel) {
			emit('zoom-change', newZoom)
		}
	}
}

onMounted(() => {
	window.addEventListener('keydown', handleKeyDown)
	window.addEventListener('keyup', handleKeyUp)
})

onUnmounted(() => {
	window.removeEventListener('keydown', handleKeyDown)
	window.removeEventListener('keyup', handleKeyUp)
	window.removeEventListener('mousemove', onMouseMove)
	window.removeEventListener('mouseup', endPan)
})

defineExpose({
	workspaceRef,
	canvasRef,
})
</script>

<template>
	<div
		ref="workspaceRef"
		:class="[
			$style.workspace,
			isPanning && $style.workspacePanning,
			isSpacePressed && $style.workspaceSpaceGrab
		]"
		data-test="crop-workspace"
		@mousedown="handleMouseDown"
		@wheel="handleWheel">
		<!-- Loading Spinner Overlay -->
		<div
			v-if="isLoadingPage"
			:class="$style.loadingOverlay"
			data-test="crop-loading-overlay">
			<NcLoadingIcon :size="36" />
		</div>

		<!-- Rendered Page & Selection Wrapper -->
		<div
			v-show="canvasWidth > 0 && canvasHeight > 0"
			:class="$style.canvasWrapper"
			:style="{ width: `${displayWidth}px`, height: `${displayHeight}px` }">
			<!-- Rendered PDF Canvas -->
			<canvas ref="canvasRef" :class="$style.pageCanvas" />

			<!-- Interactive Drag & Selection Overlay -->
			<SongPdfCropOverlay
				:crop-box="cropBox"
				:canvas-width="displayWidth"
				:canvas-height="displayHeight"
				:is-custom-cropped="isCustomCropped"
				@update:crop-box="emit('update:cropBox', $event)" />
		</div>
	</div>
</template>

<style module>
.workspace {
	position: relative;
	display: flex;
	align-items: safe center;
	justify-content: safe center;
	flex: 1 1 auto;
	min-height: 240px;
	height: 100%;
	background-color: #242424;
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	overflow: auto;
	box-sizing: border-box;
	user-select: none;
}

.workspacePanning {
	cursor: grabbing !important;
}

.workspaceSpaceGrab {
	cursor: grab;
}

.loadingOverlay {
	position: absolute;
	inset: 0;
	background-color: rgba(0, 0, 0, 0.45);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 20;
}

.canvasWrapper {
	position: relative;
	margin: auto;
	box-shadow: 0 6px 24px rgba(0, 0, 0, 0.55);
	user-select: none;
	touch-action: none;
	flex-shrink: 0;
}

.pageCanvas {
	display: block;
	width: 100%;
	height: 100%;
}

@media (max-width: 600px) {
	.workspace {
		padding: 10px;
		min-height: 200px;
	}
}
</style>
