# Locomotive Change Log

<a name="1.5.1"></a>
## 1.5.1
- Bugfix: Local item sizes were mismatching remote item sizes due to a lack of recrusiveness with PHP sFTP streams
- Organizes, cleans, and documents code

<a name="1.5.0"></a>
## 1.5.0
- Removes support for source file deletion
  - This feature is simply too dangerous to implement across varied, unknown environments
- Consolidates configuration files into central location
- Optimizes database management
- Bugfix: `lftp-path` config setting now being used

<a name="1.4.4"></a>
## 1.4.4
- `transferStarted` events now onlt emitted once per item

<a name="1.4.3"></a>
## 1.4.3
- Bugfix: Transfers with special characters in their paths are now handled appropriately

<a name="1.4.2"></a>
## 1.4.2
- Adds new config file location option
- Adds `composer.lock` file to repo

<a name="1.4.1"></a>
## 1.4.1
- Bugfix: Phinx config file location declaration was preventing **Locomotive** from being run outside the app's root directory

<a name="1.4.0"></a>
## 1.4.0
- Adds support for notifications
  - Prowl
  - Pushover
- Better exception and error handling

<a name="1.3.0"></a>
## 1.3.0
- Now supports custom post-processing user scripts
- Introduces event system to support user scripts and future notification updates, etc
- Updates PHP DocBlocks w/ `@throws` where appropriate
- Adds configuration options to README

<a name="1.1.0"></a>
## 1.1.0
- Adds support for logging to daily-rotating files

<a name="1.0.3"></a>
## 1.0.3
- Addresses [PHP Net Bug #73561](https://bugs.php.net/bug.php?id=73561) introduced in PHP version 5.6.28 and 7.0.13 by excplictily casting sFTP over SSH2-wrapped stream resources to integers
- Updates README to reflect version requirements for libssh2

<a name="1.0.2"></a>
## 1.0.2
- Bugfix: `LocalQueue::pluck()` was returning a string instead of a `Collection` when only one item exited in the local queue

<a name="1.0.1"></a>
## 1.0.1
- Updates to support Illuminate 5.2 deprecations

<a name="1.0.0"></a>
## 1.0.0
- Initial release
- Adds some basic info to the README file