# Locomotive
A PHP command line app providing scheduled, aggressive, mirrored file fetching
using segmented and parallel transfers.

**Locomotive** is intended to be run as a repeated, scheduled job (ie: crontab), and
depends on successive runs to handle the moving of transfered items, LFTP queue monitoring,
and other necessary tasks. The suggested run interval is *five minutes*, but may be run more
or less often depending on the user's needs. It is not suggested to run **Locomotive** more
often than every two minutes.

Requirements
------------

* Linux or MacOS
* PHP >= v5.6.14
* [SSH2 Extension](http://www.php.net/manual/en/book.ssh2.php) >= v0.13
  * With [libssh2](https://www.libssh2.org) >= v1.4.3
* [SQLite3 Extension](http://php.net/manual/en/book.sqlite3.php)
* [LFTP](http://lftp.yar.ru) (tested with version v4.4.13)

Installation
------------

1. Make sure to have installed [Composer](https://getcomposer.org/) globally on
your system.
2. Clone **Locomotive** to your machine. A couple good locations are your user
directory (`~/`) or somewhere in `/usr/local`.
3. Change to your **Locomotive** installation directory and install its
dependencies: `$ composer -v install --no-dev`
4. Ensure the following directories are writable by the user that will be
running **Locomotive**:
  * `app/storage`
  * `app/storage/logs`
  * `app/storage/working`
5. Ensure **Locomotive** can be executed: `$ chmod u+x locomotive`
6. Get some information about **Locomotive**: `$ ./locomotive -h`

Configuration
-------------

Many options can be passed at the command line, but there are several that **must**
be set in a config file. Any options issued at the CLI take precedence over
config file settings.

**Locomotive** looks for configuraiton files in YAML format in the following
locations, ordered by precedence:

1. `~/.config/locomotive/config.yml`
1. `~/.locomotive`
2. `config.yml`
3. `app/default-config.yml`

Storing the **Locomotive** config file at `~/.locomotive` is ideal, as it will
be preserved through any upgrades or re-installations. Simply copy the default
config file and customize as needed.

### Config File Options

* **lftp-path** - Absolute path to Lftp.
* **private-keyfile** - Absolute path to your private key file that may be used for SSH connections to the host.
* **public-keyfile** - Absolute path to your public key file that may be used for SSH connections to the host.
* **username** - Username used to establish SSH connections to the host.
* **password** - Password used to establish SSH connections to the host (not necessary if key files are provided).
* **working-dir** - Absolute path to a custom working directory (overrides default location).
* **speed-limit** - Global speed limit for transfers in bytes (default is *unlimited*).
* **connection-limit** - Per-item transfer connection limit (default is *25*).
* **transfer-limit** - Global concurrent item transfer limit (defaults to *5*).
* **max-retries** - Maximum retry attempts for a failed or interrupted transfer.
* **newer-than** - A date/time cutoff expressed as a string that can be parsed by [`strtotime()`](http://php.net/manual/en/function.strtotime.php).
* **zip-sources** - `true` or `false`; If set to `true`, items from multiple sources will be "zipped" together in alternating fashion to prevent any source(s) from claiming priority of `transfer-limit`.
* **remove-sources**
  * **remove** - `true` or `false`; If set to `true`, items will be removed from the host source after succesfully completing.
  * **exclude** - Any source paths to be excluded from removal expressed as a [YAML Sequence Collection](https://symfony.com/doc/current/components/yaml/yaml_format.html#collections).
* **speed-schedule** - The global speed limits may be scheduled for hours of the day by providing a [YAML Mapping Collection](https://symfony.com/doc/current/components/yaml/yaml_format.html#collections).
  * You may specify as many mappings as you want.
  * The times must be expressed in a format that can be parsed by [`strtotime()`](http://php.net/manual/en/function.strtotime.php).
  * The speed limit should be expressed in bytes.
  * Example: `"03:00-06:00": 51200`
* **source-target-map** - Although the source and target may be provided as command line arguments, multiple source/target relationships should be specified here as [YAML Mapping Collections](https://symfony.com/doc/current/components/yaml/yaml_format.html#collections).
  * Example: `"/absolute/path/to/source": "/absolute/path/to/target"`
* **post-processors** - If post-processing scripts are provided, they will be called in the order they are listed here with a single argument: the absolute path to the finished, moved item. Scripts should be expressed as a [YAML Sequence Collection](https://symfony.com/doc/current/components/yaml/yaml_format.html#collections).
  * Example: `- "/usr/local/bin/unrarall"` will be called as `/usr/local/bin/unrarall /path/to/item`.
* **notifications** - Notification services. Current supported services: Prowl, Pushover.
  * **enable** - `true` or `false`
  * **events** - Array of events to listen for
    * Supported events: `transferStarted`, `transferComplete`, `transferFailed`
    * Example: `[transferStarted, transferComplete, transferFailed]`
  * Other options are service-specific, but should be straightfoward.

**Locomotive** uses an intermediate location for working with the transfers it
initiates. By default, the working directory is located at `app/storage/working`,
but it may be a good idea to change the working directory, depending on the
size of the items **Locomotive** will transfering. Just make sure the working
directory is set as an absolute path, and is writable.

License
-------------

The MIT License (MIT)

Copyright (c) 2015 Joshua Smith

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

Credits
----------

The **Locomotive** icon was created by, and is used with the permission of, [Anthony Piraino](http://anthonypiraino.com/)
at [The Iconfactory](https://iconfactory.com/).