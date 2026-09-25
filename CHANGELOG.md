# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- First release
- Option to remove a MobileSheets library from the list. The library entry is removed
  only, the MobileSheets database and all files it references stay in the Nextcloud
  files, so the library can be added again at any time.
- Files added in the song editor - uploaded from the computer or attached from the
  Nextcloud files - are staged and only applied when the song is saved, like a file
  removal, so cancelling the editor leaves the library untouched.
- An added file never replaces another one: a name that is already taken in the library
  folder or by the song's naming rules gets a counter ("X (2).pdf"). Two songs that share
  a title therefore keep their own PDF, and a file added twice to one song is stored twice
  instead of leaving two entries that point at a single file.
- Option to create a new song from the songs view. The editor opens with an empty form,
  the song is only written to the MobileSheets database when the editor is saved, and
  files added before that are attached to the new song afterwards.
- Option to delete a song from the song view. The confirmation dialog points out that the
  attached files are moved to the Nextcloud trash bin, where they can be restored, while
  the song and every row pointing at it is removed from the MobileSheets database.
- Views for composers, artists, books and collections, reachable as navigation entries
  below the songs view. All four views share one implementation that is parameterized
  with the filter it manages. Each view lists the entries of its kind together with the
  songs using them, can create a new entry, and can delete an existing one. Deleting an
  entry keeps every song and only removes the entry from the songs' composers, artists,
  books or collections.
- The song filter bar is a module of its own and is shown in the songs view as well as
  in the composer, artist, book and collection views, where it filters the listed
  entries - an entry without a matching song disappears while filters are active. The
  selections are dropped when the library changes and when a view is chosen in the
  navigation, so every view starts without selections.
- Picking a composer, artist, book or collection in its view opens the songs view with
  the filter set to exactly that entry, so its songs are listed right away with
  everything a song offers. The entry itself is deleted with the button in its row,
  which keeps the songs and only removes the entry from their field.
- The filter selections are part of the URL ("…?composers=3&books=7"), so the browser
  history restores them: going back undoes a filter change or a view change and going
  forward redoes it. A URL can also be shared or bookmarked to open exactly the view it
  shows, filters included.
- Opening a song in the songs view puts it in the URL ("…&song=12") and shows the song
  viewer full screen, with a back button that returns to the song list. The browser back
  button behaves the same, and the filters of the list stay in the URL while a song is
  open, so the list comes back exactly as it was.
