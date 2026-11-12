# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-12

### Added

- First release
- Maintenance tool in the files view: "Sync file paths and metadata" replaces the
  former "Regenerate File Paths" button and checks the selected library end to
  end. One run moves every file to the path its song's naming rules produce,
  writes every attached PDF's document properties (title, composers, artists,
  books, collections) from the song data - files that already match are left
  untouched instead of being rewritten - and keeps the file facts and the
  MobileSheets hash codes file in step with both. The report dialog lists every
  file that was checked with summary counts and level filters (all / warnings /
  errors), and every entry carries a stable error code that is shown as a
  localized message and links straight to its file in the Nextcloud files - or
  to the file's folder when the file itself does not exist - and to the song in
  the editor, so a warning can be acted on with one click. Problems that belong
  to no single file, such as an unreadable hash codes file, appear as
  library-level entries in the same report.
- Option to view the PDF of a song in an external viewer. The "Open in external viewer"
  button in the song viewer opens the selected file in a new browser tab, where it is
  shown by the browser's own PDF viewer or the system's PDF application instead of the
  embedded view. For a song with several files the file of the active tab is opened.
  The song editor offers the same for every attached file, next to its delete button.
- Option to save a song in the song editor without closing it. The "Save without
  closing" button writes all changes - including the staged file additions and
  removals - and keeps the editor open, so more edits can follow right away. It is
  offered while editing an existing song; "Save and Close" works as before.
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
- The song filter bar is responsive: its filters wrap onto more rows when the window
  gets narrow, so every filter keeps a usable width down to small screens. The clear
  action then gets a row of its own instead of ending up next to a filter.
- Every view has a header bar at the top of the content area, like the Files app. It
  holds the view's "New …" button and a button that shows or hides the filter bar, and
  it takes up the strip where the button for the app navigation floats, so that button
  no longer covers the filter bar.
- The song list fills the width of the songs view - the side pane with "Select a song
  to view." is gone - and every song in it has an "Edit" button that opens its editor
  right away and a "View" button that opens the full screen song view.

### Fixed

- Saving a song no longer floods the server log with zlib "data error" warnings.
  Streams that cannot be unpacked - the ciphertext of an encrypted PDF, or any stream
  that is not Flate compressed - are skipped silently when the page count is derived.
  The hash, size and timestamp of a rewritten file are also refreshed in the
  MobileSheets hash codes file from now on, so MobileSheets does not mistake the
  metadata write for an external change to the file.
- Saving a song now also writes the metadata into PDFs that carry the "restrictions"
  protection of published files (the Standard security handler with an empty user
  password). The document properties are encrypted the way the file encrypts its
  strings - and the file's own Info object is reused, so the stamps it already had stay
  readable - which makes importing such a file into MobileSheets again yield the same
  song. Files with a user password or an unsupported protection are still skipped and
  reported in the log, as before.
- Creating a composer, book, collection or artist without being logged in answers
  `401 Unauthorized` like every other endpoint, instead of `404 Not Found`.
