### Fixed

- Removed unused counting of attempts in query
- No limit should not limit to 0 but to all

### Added

- Added real prompting 
- Added mod_studentquiz safety check
- Added the possibility to make the output be a "pseudo" html file with a table of the duplicates
- Added parameter to give priority what defines the original question of a set
- Added safety checks to marking script to use in the table output
- Added the question type to queried attributes to use in the table output
- Added parameter to limit duplicate identification to questions in the same question category

### Changed

- Duplicates which reference the original as a parent are no longer seen as duplicates

### Removed

- Removed unused counting of attempts in query
- Removed duplicate questionid column check in quiz safety check
- Removed part of quiz safety check which always fell back to the quiz_slots query anyway
- Removed query code unnecessary in Moodle 4.4
- Removed inconsistently used limit parameter
