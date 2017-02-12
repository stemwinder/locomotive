# Locomotive
A PHP command line app providing scheduled, aggressive, mirrored file fetching
using segmented and parallel transfers.

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
5. Ensure **Locomotive** can be executed: `$ chmod 755 locomotive`
6. Get some information about **Locomotive**: `$ ./locomotive -h`

Configuration
-------------

Many options can be passed at the command line, but there are several that must
be set in a config file. Any options issued at the CLI take precedence over
config file settings.

**Locomotive** looks for configuraiton files in YAML format in the following
locations, ordered by precedence:

1. `~/.locomotive`
2. `config.yml`
3. `app/default-config.yml`

Storing the **Locomotive** config file at `~/.locomotive` is ideal, as it will
be preserved through any upgrades or re-installations. Simply copy the default
config file and customize as needed.

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
