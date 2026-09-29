=== HAL Frontend Dashboard ===
Contributors: hossamadellaw
Tags: dashboard, frontend, administration
Requires at least: 7.0
Requires PHP: 8.3
Tested up to: 7.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Carrier metadata and installation entry point for HAL Frontend Dashboard.

== Description ==

HAL Frontend Dashboard is being migrated to a signed release architecture with a small WordPress Carrier and an independently managed MU Runtime.

Version 1.0.0 currently establishes the Carrier identity, release metadata, dependency lock, and source-inventory structure. The activation-only Installer is implemented locally and is loaded and invoked only when WordPress runs the Carrier activation callback. The Runtime Bootstrap/Core is also implemented locally. The complete Runtime and signed production payload are not yet complete, and no production Release, production deployment, or live-host verification is claimed. Historical inventory provenance remains unverified.

== Installation ==

This baseline is not yet an installable production release. Its activation-only Installer and Runtime Bootstrap/Core are implemented locally, but the complete Runtime and signed production payload are not yet complete, and Release/production/live verification has not been performed.

== Frequently Asked Questions ==

= Does the Carrier run updater or Runtime logic on ordinary requests? =

No. Its main file only defines identity and registers an activation callback. Installer code is loaded only if WordPress invokes that callback.

== Changelog ==

= 1.0.0 =
* Establish the canonical Carrier identity and metadata.
* Lock the Plugin Update Checker dependency for later Runtime packaging.
* Record the 49-file legacy source baseline without packaging local documentation or branding media.
