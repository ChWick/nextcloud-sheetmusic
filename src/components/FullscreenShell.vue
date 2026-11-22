<script setup lang="ts">
defineProps<{
	active: boolean
}>()
</script>

<template>
	<div :class="[$style.shell, active && $style.fullscreenContainer]">
		<slot />
	</div>
</template>

<style module>
.shell {
	display: contents;
}

.fullscreenContainer {
	display: flex;
	flex-direction: column;
	position: fixed;
	inset: 0;
	width: 100vw;
	height: 100vh;
	height: 100dvh;
	max-height: 100vh;
	max-height: 100dvh;
	background-color: var(--color-main-background);
	z-index: 100000;
}

:global(body.sheetmusic-fullscreen-active #header),
:global(body.sheetmusic-fullscreen-active #app-navigation),
:global(body.sheetmusic-fullscreen-active .app-navigation) {
	display: none !important;
}

:global(body.sheetmusic-fullscreen-active #content),
:global(body.sheetmusic-fullscreen-active #app-content),
:global(body.sheetmusic-fullscreen-active #app-content-vue) {
	margin: 0 !important;
	padding: 0 !important;
	top: 0 !important;
	left: 0 !important;
	width: 100vw !important;
	height: 100vh !important;
	max-height: 100vh !important;
	z-index: 100000 !important;
}

:global(body.sheetmusic-fullscreen-active .modal-mask),
:global(body.sheetmusic-fullscreen-active .nc-select__dropdown.vs__dropdown-menu),
:global(body.sheetmusic-fullscreen-active .v-popper__popper) {
	z-index: 100010 !important;
}
</style>
