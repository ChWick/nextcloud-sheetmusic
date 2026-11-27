<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ViewportPopover from './ViewportPopover.vue'

const props = withDefaults(defineProps<{
	open?: boolean
	popoverTitle?: string
	popoverTestId?: string
	active?: boolean
	pressed?: boolean
	size?: 'small' | 'normal'
	mainLabel: string
	mainTitle?: string
	mainTestId?: string
	menuLabel: string
	menuTestId?: string
	/** When > 0, holding the main button opens the popover without firing click. */
	longPressMs?: number
}>(), {
	open: undefined,
	popoverTitle: undefined,
	popoverTestId: undefined,
	active: false,
	pressed: false,
	size: 'normal',
	mainTitle: undefined,
	mainTestId: undefined,
	menuTestId: undefined,
	longPressMs: 0,
})

const emit = defineEmits<{
	click: []
	'update:open': [open: boolean]
}>()

const internalOpen = ref(false)
const isOpen = computed({
	get: () => (props.open !== undefined ? props.open : internalOpen.value),
	set: (val: boolean) => {
		internalOpen.value = val
		emit('update:open', val)
	},
})

let longPressTimer: ReturnType<typeof setTimeout> | null = null
let longPressTriggered = false

function clearLongPress() {
	if (longPressTimer !== null) {
		clearTimeout(longPressTimer)
		longPressTimer = null
	}
}

function onMainPointerDown(event: PointerEvent) {
	if (event.pointerType === 'mouse' && event.button !== 0) return
	if (!props.longPressMs || props.longPressMs <= 0) return
	longPressTriggered = false
	clearLongPress()
	longPressTimer = setTimeout(() => {
		longPressTriggered = true
		longPressTimer = null
		isOpen.value = true
	}, props.longPressMs)
}

function onMainClick() {
	clearLongPress()
	if (longPressTriggered) {
		longPressTriggered = false
		return
	}
	emit('click')
}

onUnmounted(() => {
	clearLongPress()
})
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		:title="popoverTitle"
		:pane-test-id="popoverTestId"
		:pane-class="$style.popoverPane"
		:gap="6">
		<template #trigger="{ toggle }">
			<div :class="$style.splitGroup">
				<NcButton
					:variant="active ? 'primary' : 'secondary'"
					:size="size"
					:class="$style.mainBtn"
					:aria-pressed="pressed"
					:aria-label="mainLabel"
					:title="mainTitle || mainLabel"
					:data-test="mainTestId"
					@pointerdown="onMainPointerDown"
					@pointerup="clearLongPress"
					@pointercancel="clearLongPress"
					@pointerleave="clearLongPress"
					@click="onMainClick">
					<template #icon>
						<slot name="icon" />
					</template>
					<slot name="label" />
				</NcButton>
				<NcButton
					:variant="isOpen || active ? 'primary' : 'secondary'"
					:size="size"
					:class="$style.arrowBtn"
					:aria-expanded="isOpen"
					:aria-label="menuLabel"
					:title="menuLabel"
					:data-test="menuTestId"
					@click="toggle">
					<template #icon>
						<ChevronDownIcon :size="size === 'small' ? 16 : 18" />
					</template>
				</NcButton>
			</div>
		</template>

		<slot />
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
