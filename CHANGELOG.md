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
