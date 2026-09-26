=== Modern Editor ===
Contributors: PeopleInside
Tags: tinymce, classic editor, gutenberg, dark mode, wysiwyg, editor
Requires at least: 6.0
Tested up to: 7.1.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern editor for WordPress. Disables Gutenberg and replaces the classic editor with modern TinyMCE (version 7 or 8), loaded via CDN or offline, featuring dark mode support and an advanced toolbar.

== Description ==

Modern Editor solves two common problems:

1. **Outdated TinyMCE**: WordPress includes an internally outdated version of TinyMCE. This plugin replaces it with modern TinyMCE (major version 7 or 8, selectable from settings), loaded via CDN (jsDelivr) or entirely offline, under the GPL license, without requiring any accounts or API keys.
2. **User Experience**: It provides a clean interface with dark mode support and an advanced toolbar ready to use out of the box.

== Frequently Asked Questions ==

= Are the offline TinyMCE files legal to distribute? =

Yes. TinyMCE is distributed by Tiny Technologies under the GNU GPLv2 or later license. The files included in the plugin (and those downloadable from the settings) come directly from the official "tinymce" package published on npm, without any modifications to the code. The GPL license is explicitly declared in the editor initialization (`license_key: 'gpl'`).

== Changelog ==

= 1.4.0 =
* Feature: Choose default editor between Modern Classic Editor and Block Editor (Gutenberg) in plugin settings.
* Feature: Allow users to switch editors when creating or editing posts and pages, configurable in settings.
* Feature: Direct "Add New (Classic)" and "Add New (Blocks)" submenu links under Posts and Pages and in the top Admin Bar (+ New).
* Feature: Row actions "Edit (Classic)" and "Edit (Blocks)" in post and page listing tables.
* Feature: One-click editor switcher sidebar metabox in the editor with confirmation and content preservation.
* Fix: Anchor link button is now strictly highlighted/active only for anchor links (#) and bookmarks, resolving the issue where regular links activated both buttons.
* Fix: Double-clicking standard links opens the standard link dialog instead of the anchor dialog.
* Feature: Add image alignment and text wrapping options (align left/right with text wrap, center, inline) to allow writing text near images.
* Feature: Quick image alignment context toolbar when clicking images in the editor.
* Feature: Support image alignment in WordPress Media Library insertion modal and image dialog class list.
* Setting: Add option in settings to toggle image alignment and text wrapping controls.

= 1.3.9 =
* Maintenance release.
* Improved update - fix

= 1.3.8 =
* Update release workflow to include version in ZIP name.
* Refactor MCE_Updater class for clarity and efficiency.

= 1.3.7 =
* Fix: replace unsafe HTML decode in editor init.

= 1.3.4 =
* Initial structured release.
