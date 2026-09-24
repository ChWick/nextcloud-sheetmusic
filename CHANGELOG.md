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
