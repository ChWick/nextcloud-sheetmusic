<script setup lang="ts">
import type { Component } from 'vue'
import type { Library } from '../types/library'
import { translate as t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import AccountMusic from 'vue-material-design-icons/AccountMusic.vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import BookIcon from 'vue-material-design-icons/Book.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CloudOffOutline from 'vue-material-design-icons/CloudOffOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import DatabaseImport from 'vue-material-design-icons/DatabaseImport.vue'
import DramaMasks from 'vue-material-design-icons/DramaMasks.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderMultiple from 'vue-material-design-icons/FolderMultiple.vue'
import FolderMusicOutline from 'vue-material-design-icons/FolderMusicOutline.vue'
import FolderPlusOutline from 'vue-material-design-icons/FolderPlusOutline.vue'
import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import GuitarAcoustic from 'vue-material-design-icons/GuitarAcoustic.vue'
import Metronome from 'vue-material-design-icons/Metronome.vue'
import Music from 'vue-material-design-icons/Music.vue'
import MusicClefTreble from 'vue-material-design-icons/MusicClefTreble.vue'
import PlaylistMusic from 'vue-material-design-icons/PlaylistMusic.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import TagIcon from 'vue-material-design-icons/Tag.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'

interface NavEntry {
	view: string
	label: string
	icon: Component
}

defineProps<{
	libraries: Library[]
	selectedLibrary: number
	selectedView: string
	currentLibrary?: Library | null
	isPublicShare: boolean
	publicLibraryPath?: string
	selectedLibraryPath: string
	formatLibraryOption: (library: Library) => string
}>()

const emit = defineEmits<{
	'select-library': [libraryId: number]
	'enter-offline': []
	'open-add-library': []
	'open-import-library': []
	'open-share': []
	'open-settings': []
}>()

const LIBRARY_NAV_ITEMS: NavEntry[] = [
	{ view: 'setlists', label: t('sheetmusic', 'Setlists'), icon: PlaylistMusic },
	{ view: 'composers', label: t('sheetmusic', 'Composers'), icon: AccountMusic },
	{ view: 'artists', label: t('sheetmusic', 'Artists'), icon: AccountStar },
	{ view: 'books', label: t('sheetmusic', 'Books'), icon: BookIcon },
	{ view: 'collections', label: t('sheetmusic', 'Collections'), icon: FolderMultiple },
	{ view: 'genres', label: t('sheetmusic', 'Genres'), icon: DramaMasks },
	{ view: 'keys', label: t('sheetmusic', 'Keys'), icon: MusicClefTreble },
	{ view: 'customgroups', label: t('sheetmusic', 'Custom Groups'), icon: TagIcon },
	{ view: 'years', label: t('sheetmusic', 'Years'), icon: Calendar },
	{ view: 'signatures', label: t('sheetmusic', 'Time Signatures'), icon: Metronome },
	{ view: 'sourcetypes', label: t('sheetmusic', 'Source Types'), icon: FileDocumentOutline },
	{ view: 'instruments', label: t('sheetmusic', 'Instruments'), icon: GuitarAcoustic },
	{ view: 'arrangers', label: t('sheetmusic', 'Arrangers'), icon: AccountMusic },
]

function onLibraryChange(event: Event) {
	const value = Number((event.target as HTMLSelectElement).value)
	if (Number.isFinite(value)) {
		emit('select-library', value)
	}
}
</script>

<template>
	<NcAppNavigation :aria-label="t('sheetmusic', 'Sheet Music libraries')">
		<NcAppNavigationItem :name="t('sheetmusic', 'Library')">
			<template #icon>
				<FolderMusicOutline :size="20" />
			</template>
			<span v-if="isPublicShare" :class="[$style.librarySelect, $style.publicLibraryLabel]">
				{{ publicLibraryPath ?? selectedLibraryPath }}
			</span>
			<select
				v-else
				:value="selectedLibrary"
				:class="$style.librarySelect"
				data-test="library-selector"
				@change="onLibraryChange">
				<option disabled value="-1">{{ t('sheetmusic', 'Select library') }}</option>
				<option v-for="library in libraries" :key="library.id" :value="library.id">
					{{ formatLibraryOption(library) }}
				</option>
			</select>
		</NcAppNavigationItem>

		<template v-if="selectedLibrary >= 0">
			<NcAppNavigationItem
				:name="t('sheetmusic', 'Songs')"
				:active="selectedView === 'songs' || selectedView === 'new-song'"
				:to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'songs' } }">
				<template #icon>
					<Music :size="20" />
				</template>
			</NcAppNavigationItem>
			<li role="separator" :class="$style.navSeparator" aria-hidden="true" />
			<NcAppNavigationItem
				v-for="item in LIBRARY_NAV_ITEMS"
				:key="item.view"
				:name="item.label"
				:active="selectedView === item.view"
				:to="{ name: 'LibraryView', params: { library: selectedLibrary, view: item.view } }">
				<template #icon>
					<component :is="item.icon" :size="20" />
				</template>
			</NcAppNavigationItem>
			<li role="separator" :class="$style.navSeparator" aria-hidden="true" />
			<NcAppNavigationItem
				:name="t('sheetmusic', 'Sub-Libraries')"
				:active="selectedView === 'sublibraries'"
				:to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'sublibraries' } }">
				<template #icon>
					<FolderSyncOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				v-if="!isPublicShare && (!currentLibrary || !currentLibrary.isShared)"
				:name="t('sheetmusic', 'Shares')"
				:active="selectedView === 'shares'"
				:to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'shares' } }">
				<template #icon>
					<ShareVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<template v-if="!isPublicShare">
				<NcAppNavigationItem
					:name="t('sheetmusic', 'Integrity Audit')"
					:active="selectedView === 'integrity'"
					:to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'integrity' } }">
					<template #icon>
						<ShieldCheck :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('sheetmusic', 'Tools')"
					:active="selectedView === 'tools'"
					:to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'tools' } }">
					<template #icon>
						<Wrench :size="20" />
					</template>
				</NcAppNavigationItem>
				<li role="separator" :class="$style.navSeparator" aria-hidden="true" />
				<NcAppNavigationItem
					:name="t('sheetmusic', 'Trash')"
					:active="selectedView === 'trash'"
					:to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'trash' } }">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</template>

		<template v-if="!isPublicShare">
			<li role="separator" :class="$style.navSeparator" aria-hidden="true" />
			<NcAppNavigationItem :name="t('sheetmusic', 'Offline mode')" @click="emit('enter-offline')">
				<template #icon>
					<CloudOffOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>

		<template v-if="!isPublicShare" #footer>
			<div :class="$style.footerArea">
				<div :class="$style.footerRow">
					<NcActions
						:class="$style.footerActionMenu"
						:menu-name="t('sheetmusic', 'Add library')"
						placement="top-start">
						<template #icon>
							<Plus :size="20" />
						</template>
						<NcActionButton @click="emit('open-add-library')">
							<template #icon>
								<FolderPlusOutline :size="20" />
							</template>
							{{ t('sheetmusic', 'Add Sheet Music library') }}
						</NcActionButton>
						<NcActionButton @click="emit('open-import-library')">
							<template #icon>
								<DatabaseImport :size="20" />
							</template>
							{{ t('sheetmusic', 'Import MobileSheets library') }}
						</NcActionButton>
					</NcActions>
					<NcButton
						v-if="selectedLibrary >= 0 && currentLibrary && !currentLibrary.isShared"
						type="button"
						variant="tertiary"
						:title="t('sheetmusic', 'Share library')"
						:aria-label="t('sheetmusic', 'Share library')"
						:class="$style.settingsButton"
						@click="emit('open-share')">
						<template #icon>
							<ShareVariantOutline :size="20" />
						</template>
					</NcButton>
					<NcButton
						type="button"
						variant="tertiary"
						:title="selectedLibrary >= 0 ? t('sheetmusic', 'Library Settings') : t('sheetmusic', 'Settings')"
						:aria-label="selectedLibrary >= 0 ? t('sheetmusic', 'Library Settings') : t('sheetmusic', 'Settings')"
						:class="$style.settingsButton"
						@click="emit('open-settings')">
						<template #icon>
							<CogIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</template>
	</NcAppNavigation>
</template>

<style module>
.librarySelect {
	width: 100%;
	padding: 8px;
	margin: 8px 0;
}

.publicLibraryLabel {
	display: flex;
	align-items: center;
	font-weight: 500;
	padding: 0 8px;
}

.navSeparator {
	list-style: none;
	border-top: 1px solid var(--color-border);
	margin: 4px 8px;
}

.footerArea {
	display: flex;
	flex-direction: column;
	width: 100%;
}

.footerRow {
	display: flex;
	align-items: center;
	width: 100%;
}

.footerActionMenu {
	flex: 1;
	min-width: 0;
}

.footerActionMenu :global(.action-item__menutoggle) {
	width: 100%;
	justify-content: flex-start;
	padding: 10px 16px;
	box-sizing: border-box;
}

.settingsButton {
	margin-inline-end: 8px;
	flex-shrink: 0;
}
</style>
