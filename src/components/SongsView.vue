<template>
  <div :class="$style.songArea">
    <div :class="$style.filterBarWrapper"><SongFilterBar
      v-model:selected-composers="selectedComposers"
      v-model:selected-books="selectedBooks"
      v-model:selected-collections="selectedCollections"
      v-model:selected-artists="selectedArtists"
      :composers="composers"
      :books="books"
      :collections="collections"
      :artists="artists"/>
    </div>
    <div :class="$style.listColumn">
      <NcButton
        variant="primary"
        :class="$style.newSongButton"
        @click="startNewSong">
        {{ t('mobilesheetsmanager', 'New song') }}
      </NcButton>
      <ul :class="$style.songList">
        <li v-for="song in filteredSongs" :key="song.id">
          <button type="button"
                  :class="$style.songButton"
                  :aria-pressed="selectedSong?.id === song.id"
                  @click="selectSong(song)">
            {{ song.title }}
          </button>
        </li>
        <li v-if="!isLoadingSongs && !fileError && songs.length === 0">
          {{ t('mobilesheetsmanager', 'No songs found in this library.') }}
        </li>
        <li v-else-if="!isLoadingSongs && !fileError && songs.length > 0 && filteredSongs.length === 0">
          {{ t('mobilesheetsmanager', 'No songs match the selected filters.') }}
        </li>
      </ul>
    </div>
    <div :class="$style.viewerArea">
      <SongEditor v-if="isCreatingSong"
                  :song="null"
                  :library-id="selectedLibrary"
                  :composers="composers"
                  :books="books"
                  :collections="collections"
                  :artists="artists"
                  @saved="handleSaved"
                  @cancel="isCreatingSong = false" />
      <SongViewer v-else
                  :song="selectedSong"
                  :library-id="selectedLibrary"
                  :composers="composers"
                  :books="books"
                  :collections="collections"
                  :artists="artists"
                  @saved="handleSaved"
                  @updated="handleLibraryDataUpdate" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { generateOcsUrl } from '@nextcloud/router';
import { ref, computed, watch } from 'vue';
import NcButton from '@nextcloud/vue/components/NcButton';
import SongEditor from '../components/SongEditor.vue';
import SongFilterBar from '../components/SongFilterBar.vue';
import SongViewer from '../components/SongViewer.vue';
import type { Artist, Song, Composer, Book, Collection, LibraryData } from '../types/song';

// Props from parent (App.vue)
const props = defineProps<{ selectedLibrary: number }>();

// Local state for this view
const songs = ref<Song[]>([]);
const composers = ref<Composer[]>([]);
const books = ref<Book[]>([]);
const collections = ref<Collection[]>([]);
const artists = ref<Artist[]>([]);

const selectedComposers = ref<Composer[]>([]);
const selectedBooks = ref<Book[]>([]);
const selectedCollections = ref<Collection[]>([]);
const selectedArtists = ref<Artist[]>([]);

const selectedSong = ref<Song | null>(null);
const fileError = ref('');
const isLoadingSongs = ref(false);
/** The editor is open for a song that does not exist yet. */
const isCreatingSong = ref(false);

// Computed filtered list
const filteredSongs = computed(() => {
  const composerIds = new Set(selectedComposers.value.map(c => c.id));
  const bookIds = new Set(selectedBooks.value.map(b => b.id));
  const collectionIds = new Set(selectedCollections.value.map(c => c.id));
  const artistIds = new Set(selectedArtists.value.map(a => a.id));
  return songs.value.filter(song => {
    if (composerIds.size && !(song.composers?.some(c => composerIds.has(c.id)))) return false;
    if (bookIds.size && !(song.books?.some(b => bookIds.has(b.id)))) return false;
    if (collectionIds.size && !(song.collections?.some(c => collectionIds.has(c.id)))) return false;
    if (artistIds.size && !(song.artists?.some(a => artistIds.has(a.id)))) return false;
    return true;
  });
});

function handleLibraryDataUpdate(data: LibraryData) {
  songs.value = data.songs ?? [];
  composers.value = data.composers ?? [];
  books.value = data.books ?? [];
  collections.value = data.collections ?? [];
  artists.value = data.artists ?? [];
  // Preserve selected song if still present
  if (selectedSong.value) {
    const updated = songs.value.find(s => s.id === selectedSong.value?.id);
    if (updated) selectedSong.value = updated;
  }
}

async function loadLibraryData(id: number) {
  isLoadingSongs.value = true;
  fileError.value = '';
  try {
    const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/songs'), { params: { id } });
    handleLibraryDataUpdate(response.data.ocs.data as LibraryData);
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not load the songs in this library.');
  } finally {
    isLoadingSongs.value = false;
  }
}

// Watch for library prop changes
watch(() => props.selectedLibrary, (newLib) => {
  if (newLib) loadLibraryData(newLib);
}, { immediate: true });

function selectSong(song: Song) {
  // Selecting a song leaves the editor for a new song behind, like pressing Cancel.
  isCreatingSong.value = false;
  selectedSong.value = song;
  // Emit to parent if needed (e.g., for other UI parts)
  emit('select-song', song);
}

/** Open the editor for a song that does not exist yet. Cancelling leaves no trace. */
function startNewSong() {
  selectedSong.value = null;
  isCreatingSong.value = true;
}

/** The editor saved: refresh the library data and select the song that was saved. */
function handleSaved(data: LibraryData, songId?: number) {
  isCreatingSong.value = false;
  handleLibraryDataUpdate(data);

  if (songId === undefined) return;
  const saved = songs.value.find(song => song.id === songId);
  if (saved) selectSong(saved);
}

// Emit events to parent
const emit = defineEmits<{
	(e: 'select-song', song: Song): void
	(e: 'saved'): void
	(e: 'updated'): void
}>();
</script>

<style module>
.songArea {
  display: grid;
  grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
  grid-template-rows: auto 1fr;
  gap: 24px;
  align-items: start;
}
.songList {
  margin: 0;
  padding: 0;
  list-style: none;
}
.songList li {
  border-bottom: 1px solid var(--color-border);
}
.songButton {
  width: 100%;
  padding: 10px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.songButton[aria-pressed="true"] {
  background: var(--color-background-hover);
}
.filterBarWrapper {
  grid-column: 1 / -1;
  margin-bottom: 12px;
}

.viewerArea {
  grid-column: 2;
  grid-row: 2;
}

.listColumn {
  grid-column: 1;
  grid-row: 2;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  overflow-y: auto;
}

.newSongButton {
  width: 100%;
  justify-content: center;
}

.viewerArea {
  overflow-y: auto;
}

</style>
