<?php

/**
 * Locomotive
 *
 * Copyright (c) 2015 Joshua Smith
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package  Locomotive
 */

namespace Locomotive;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Finder\Finder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Locomotive\Configuration\Configurator;
use Locomotive\Lftp;
use Locomotive\Database\Models\LocalQueue;
use Locomotive\Database\Models\Metrics;

class Locomotive
{
    /**
     * @var ConsoleLogger
     **/
    protected $logger;

    /**
     * @var InputInterface
     **/
    protected $input;

    /**
     * @var OutputInterface
     **/
    protected $output;

     /**
     * @var Capsule
     **/
    protected $DB;

    /**
     * @var Lftp
     **/
    public $lftp;

     /**
     * @var SSH2 Resource
     **/
    protected $sshSession;

    /**
     * Datetime of last run.
     *
     * @var String
     **/
    public $lastRun;

    /**
     * A unique run ID.
     *
     * @var String
     **/
    public $runId;

    /**
     * Whether or not lftp has a background proc running.
     *
     * @var Bool
     **/
    public $isLftpBackgrounded;

    /**
     * The process ID of the lftp terminal attachment.
     *
     * @var Int
     **/
    public $lftpTerminalId = null;

    /**
     * Current count of items in lftp queue.
     *
     * @var Int
     **/
    public $lftpQueueCount;

    /**
     * Current mapping of active lftp queue items to known local DB
     * queue items.
     *
     * @var Collection
     **/
    public $mappedQueue;

    /**
     * Newly initiated transfers.
     *
     * @var Collection
     **/
    public $newTransfers;

    /**
     * Newly moved finished items.
     *
     * @var Collection
     **/
    public $movedItems;

    /**
     * @var Array
     **/
    protected $arguments = array();

    /**
     * @var Array
     **/
    protected $options = array();

    /**
     * Class Constructor.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     * @param ConsoleLogger   $logger Console Logger
     */
    function __construct(
        InputInterface $input,
        OutputInterface $output,
        ConsoleLogger $logger,
        Capsule $DB
    ) {
        $this->input = $input;
        $this->output = $output;
        $this->logger = $logger;
        $this->DB = $DB;

        // initial Locomotive setup, checks, and validation
        $this->arguments['host'] = $input->getArgument('host');

        $this->dependencyCheck()
             ->setPaths()
             ->validatePaths()
             ->bootstrap($input, $logger);
    }

    /**
     * Bootstrap Locomotive.
     *
     * @param InputInterface  $input  An Input instance
     * @param ConsoleLogger   $logger Console Logger
     *
     * @return Locomotive
     **/
    private function bootstrap(InputInterface $input, ConsoleLogger $logger)
    {
        // load and merge default and user config values with CLI input
        $config = new Configurator($input, $logger);
        $this->options = $config->getConfig();

        // grab last run time and update it
        $now = Carbon::now();
        $metrics = Metrics::firstOrNew(['id' => 1]);
        $this->lastRun = isset($metrics->last_run) ? Carbon::parse($metrics->last_run) : $now;
        $metrics->last_run = $now->toDateTimeString();
        $metrics->save();

        $this->runId = uniqid();

        // setting working directory
        if (null == $this->options['working-dir']) {
            $this->options['working-dir'] = BASEPATH . '/app/storage/working/';
        }

        // setting up lftp command builder
        $this->lftp = new Lftp(
            array_merge($this->arguments, $this->options),
            $logger
        );

        return $this;
    }

    /**
     * Checks for any missing system dependencies. For now, that's just `lftp`.
     *
     * @return Locomotive
     **/
    private function dependencyCheck()
    {
        // check for lFTP
        if (! `which lftp`) {
            $this->logger->critical(
                "LFTP is either not installed on this system, or not in the path.\n"
                ."Please provide the path in the Locomotive config or find out\n"
                ."more about LFTP by visiting: https://github.com/lavv17/lftp"
            );

            return 0;
        }

        return $this;
    }

    /**
     * Sets the class' paths or path lists.
     *
     * @return Locomotive
     **/
    private function setPaths()
    {
        // setting SOURCE to either single path or list
        $sourceArg = $this->input->getArgument('source');
        $this->arguments['source'] = str_contains($sourceArg, ':')
            ? explode(':', $sourceArg)
            : $sourceArg
        ;

        // setting TARGET to either single path or list
        $targetArg = $this->input->getArgument('target');
        $this->arguments['target'] = str_contains($targetArg, ':')
            ? explode(':', $targetArg)
            : $targetArg
        ;

        return $this;
    }

    /**
     * Validates the provided path schema.
     *
     * @return Locomotive
     **/
    private function validatePaths()
    {
        // validating list matching for source/target mapping purposes
        if (is_array($this->arguments['target']) && is_array($this->arguments['source'])) {
            if (count($this->arguments['source']) != count($this->arguments['target'])) {
                $this->logger->error('When both the SOURCE and TARGET are path lists, they are assumed to be mapped and their number of paths must match.');

                return 0;
            }
        } elseif (is_array($this->arguments['target']) && ! is_array($this->arguments['source'])) {
            $this->logger->error('The provided TARGET is a list of paths, but the SOURCE is not. I don\'t know what to do.');

            return 0;
        }

        return $this;
    }


    /**
     * Gets a source path's mapped target path, or return the target path if no
     * mapping is specified.
     *
     * @param string $sourceDir The source directory to search for a mapped target
     *
     * @return string A mapped target path
     **/
    private function mapTargetFromSource($sourceDir)
    {
        if (is_array($this->arguments['target']) && is_array($this->arguments['source'])) {
            // get the key for the matching array source
            $matchingSource = array_filter($this->arguments['source'], function($source, $key) use ($sourceDir) {
                return strstr($source, rtrim($sourceDir, '/'));
            }, ARRAY_FILTER_USE_BOTH);

            // return the target path at the key matching the source, effectively
            // mapping them together
            return $this->arguments['target'][key($matchingSource)];
        } else {
            return $this->arguments['target'];
        }
    }

    /**
     * Gets the status of the lftp queue.
     *
     * @return string The response from `lftp queue`
     **/
    public function getLftpStatus()
    {
        $this->logger->debug('Checking lftp status via queue attachment attempt.');

        $status = $this->lftp
            ->addCommand('queue')
            ->execute(false, true);

        // test for backgrounded process
        if (strpos(end($status), 'backgrounded') !== false) {
            $this->logger->debug('It appears that lftp is NOT backgrounded.');

            $this->isLftpBackgrounded = false;
        } else {
            $this->logger->debug('It appears that lftp IS backgrounded.');

            $this->isLftpBackgrounded = true;
        }

        return $status;
    }

    /**
     * Parses the raw output from the lftp queue. Builds a queue mapping for
     * lftp and local items, and sets important class variables like the lftp
     * queue count.
     *
     * @param array $queueOutput The raw lftp queue terminal output.
     *
     * @return Locomotive
     **/
    public function parseLftpQueue(Array $queueOutput)
    {
        $this->logger->debug('An active lftp queue was found. Begin parsing.');

        // use Illuminate\Collection to ease the pain
        $lftpQueue = Collection::make($queueOutput);
        $lftpQueue->pop();

        // parse terminal ID out of lftp queue output
        preg_match("/(\\[\\d+\\]).+/", $lftpQueue->first(), $matches);
        $this->lftpTerminalId = trim($matches[1], '[]');
        $this->logger->debug("Setting the lftp terminal attachment ID to $this->lftpTerminalId.");

        // seek to beginning of active items
        $activeKey = $lftpQueue->search(function($item) {
            return strstr($item, 'Now executing:');
        });

        // seek to beginning of queued items
        $queuedKey = $lftpQueue->search(function($item) {
            return strstr($item, 'Commands queued:');
        });

        // seperate items into new collections
        if ($queuedKey !== false) {
            $activeItems = $lftpQueue->slice($activeKey, ($queuedKey - $activeKey));
            $queuedItems = $lftpQueue->slice(($queuedKey + 1));
        } else {
            $activeItems = $lftpQueue->slice($activeKey);
            $queuedItems = null;
        }

        // setting items to empty Collections if they return as empty
        $activeItems = empty($activeItems) ? new Collection : $activeItems;
        $queuedItems = empty($queuedItems) ? new Collection : $queuedItems;

        // clean active items
        $activeItems->transform(function($item, $key) {
            if ($key === 0) {
                return substr($item, 15);
            } else {
                return ltrim($item, "\t-");
            }
        });

        // clean queued items
        $queuedItems->transform(function($item, $key) {
            return ltrim($item);
        });

        // create a merged list of all lftp queue items
        $mergedLftpQueue = $activeItems->merge($queuedItems);

        // get all unfinished items from local DB queue
        $localQueue = LocalQueue::notFinished()->get();

        // map lftp queue items to local DB queue items
        $mappedQueue = new Collection;
        $localQueue->each(function($localItem, $key) use ($mappedQueue, $mergedLftpQueue) {
            $mappedItem = $mergedLftpQueue->search(function($aItem, $aKey) use ($localItem) {
                return strstr($aItem, $localItem->name);
            });

            if ($mappedItem !== false) {
                $mappedQueue->put($localItem->id, $mappedItem);
            }
        });

        // set class variable with count of current active items
        $this->lftpQueueCount = $mappedQueue->count();
        $this->logger->debug("Recording the lftp queue count as $this->lftpQueueCount");

        // set class variable with mapped queue
        $this->mappedQueue = $mappedQueue;

        // TODO: parse stats
        /*$parsedQueue = array();
        foreach ($mappedQueue as $queueId => $item) {
            preg_match('/(\[\d+\]) (pget -c|mirror -c).+? -- (\d+?[a-zA-Z]\/\d+?[a-zA-Z]) (\(\d+?%\))/', $item, $matches);

            $parsedQueue[$queueId] = [
                'id' => $queueId,
                'lftpQueueEntry' => $item,
                'parseTest' => $matches,
            ];
        }*/

        return $this;
    }

    /**
     * Updates item flags in the local database queue by comparing item stats
     * such as file size and count.
     *
     * @return Locomotive
     **/
    public function updateLocalQueue()
    {
        $this->logger->debug('Beginning local DB queue update.');

        if (! isset($this->mappedQueue)) {
            // a backgrounded lftp queue was never detected; assume it has cleared
            // since last run and check local items
            $localQueue = LocalQueue::notForRun($this->runId)
                ->notFinished()
                ->notFailed()
                ->get();
        } else {
            // get all unfinished items from local DB queue that don't exist
            // in mapped queue (aren't currently active in lftp)
            $localQueue = LocalQueue::notForRun($this->runId)
                ->notFinished()
                ->notFailed()
                ->lftpActive($this->mappedQueue->keys())
                ->get();
        }

        // mark items finished if file size matches
        if ($localQueue->count() > 0) {
            $this->logger->debug('Unfinished items found in local queue. Checking for completeness.');

            $localQueue->each(function($item, $key) {
                // seeking to file location
                $finderItem = new Finder();
                $finderItem->in($this->options['working-dir'])
                           ->name($item->name);
                $finderItem = current(iterator_to_array($finderItem));

                if ($finderItem != false) {
                    $itemSize = $this->calculateItemSize($finderItem);

                    // check file size and mark as finished
                    if (
                        $item->size_bytes == $itemSize['itemSize']
                        && $item->file_count == $itemSize['fileCount']
                    ) {
                        $item->is_finished = true;
                        $item->save();
                    } else {
                        // mark item as failed if there is a size or count mismatch
                        $item->is_failed = true;
                        $item->save();
                    }
                } else {
                    // mark item as failed if it can't be found locally
                    $item->is_failed = true;
                    $item->save();
                }

                // TODO: handle all local queue items marked as inactive and unfinished
                // by re-enqueueing up to a maximum [n] times. Will need to modify
                // `$this->filterSourceItems()`.
            });
        }
        
        return $this;
    }

    /**
     * Records a transfer in the local queue.
     *
     * @param Finder $item The transfered item
     * @param string $transferPath The absolute transfer path
     * 
     * @return LocalQueue The Eloquent model
     **/
    private function recordItemToQueue($item, $transferPath)
    {
        // get item size
        $itemSize = $this->calculateItemSize($item);

        // create a hash; clean `$transferPath`; get the mapped target for the item
        $hash = $this->makeHash($item->getBasename(), $item->getMTime());
        $transferPath = rtrim($transferPath, '/');
        $target = $this->mapTargetFromSource($transferPath);

        // write the item to the local DB queue
        $localQueue = LocalQueue::create([
            'hash' => $hash,
            'run_id' => $this->runId,
            'name' => $item->getBasename(),
            'host' => $this->arguments['host'],
            'source_dir' => $transferPath,
            'size_bytes' => $itemSize['itemSize'],
            'file_count' => $itemSize['fileCount'],
            'last_modified' => date('Y-m-d H:i:s', $item->getMTime()),
            'started_at' => date('Y-m-d H:i:s'),
            'target_dir' => $target,
        ]);

        return $localQueue;
    }

    /**
     * Initiates transfers based on number of available slots.
     *
     * Handles SSH session setup; source item list retreival, filtering, and
     * zipping; speed limit setting; lftp command issuance; and recording
     * active/new transfers to the local queue.
     *
     * @param int $availableSlots Number of slots available for transfer
     * 
     * @return Locomotive
     **/
    public function initiateTransfers($availableSlots = null)
    {
        if (is_null($availableSlots)) {
            $lftpQueueCount = $this->lftpQueueCount ?: 0;
            
            // assume lftp queue is innactive
            $availableSlots = $this->options['transfer-limit'] - $lftpQueueCount;

            if ($availableSlots === 0) {
                $this->logger->info('All transfer slots are full.');

                // no transfers occured for program output
                $this->newTransfers = false;

                return;
            } else {
                $this->logger->info("Setting available transfer slots to $availableSlots.");
            }
        }

        // setup ssh/sftp session
        $this->createSshSession();

        // get listing of items from all source directories
        $this->logger->info('Retrieving all available items from host source(s).');
        $sourceItems = $this->getSourceItems();

        // filter source items list of any seen/fetched items
        $this->logger->debug('Applying filters to source items.');
        $sourceItems = $this->filterSourceItems($sourceItems);

        // zip items together into one collection; limit to available slots
        $this->logger->debug('Building the transfer list.');
        $transferList = $this->buildTransferList($sourceItems, $availableSlots);

        // set speed limit before initiating transfers
        $this->lftp->setSpeedLimit($this->options['speed-limit']);

        // issue lftp commands depending on `isDir()` or `isFile()`
        $transferList->each(function($item) {
            // parse out path to send to lftp
            $transferPath = $item->getPath();
            preg_match("@ssh2.sftp://(.+?)/(.+)@us", $transferPath, $matches);
            $transferPath = "/$matches[2]/";

            if ($item->isDir()) {
                $files = new Finder();
                $files->depth('== 0');

                // constrain search to source directory
                $files->in($item->getPath() . '/' . $item->getBasename());

                if (iterator_count($files) < 8) {
                    // mirror directory using pget
                    $this->lftp->mirrorDir(
                        $transferPath . $item->getBasename(),
                        $this->options['connection-limit'],
                        false,
                        true
                    );
                } else {
                    // mirror using parallel
                    $this->lftp->mirrorDir(
                        $transferPath . $item->getBasename(),
                        false,
                        $this->options['connection-limit'],
                        true
                    );
                }
            } elseif ($item->isFile()) {
                // fetch file using pget
                $this->lftp->pgetFile(
                    $transferPath . $item->getBasename(),
                    $this->options['connection-limit'],
                    true
                );
            }

            // record transfer in local queue
            $this->recordItemToQueue($item, $transferPath);
        });

        if (count($transferList) > 0) {
            $this->logger->debug('Recorded new transfers to the local queue.');

            // execute transfer
            $this->lftp->execute(true, $this->isLftpBackgrounded, $this->lftpTerminalId);
            
            // write transfered items to global variable for output
            $this->newTransfers = $transferList;
        } else {
            $this->logger->info('There are no new items available for transfer.');

            // no transfers occured for program output
            $this->newTransfers = false;
        }

        return $this;
    }

    /**
     * Moves finished items to their target destination; Marks as moved in the
     * local database queue.
     *
     * @return Locomotive
     **/
    public function moveFinished()
    {
        $this->logger->debug('Moving finished items.');

        // getting finished items that haven't been moved yet
        $finished = LocalQueue::finished()
                              ->notMoved()
                              ->get();

        if ($finished->count() > 0) {
            $this->logger->debug('Finished items were found in the local queue.');
            $workingDir = $this->options['working-dir'];

            $finished->each(function($item, $key) use ($workingDir) {
                // TODO: check for existance of target directory

                // move item
                $targetDir = rtrim($item->target_dir, '/') . '/';
                exec("mv {$workingDir}{$item->name} {$targetDir} 2>&1", $output, $exitCode);

                // deal with exit code failures
                if ($exitCode != 0) {
                    $this->logger->error(implode(' ', $output));

                    exit(1);
                }

                // mark as moved in local DB queue
                $item->is_moved = true;
                $item->save();
            });

            // set class variable with moved items
            $this->movedItems = $finished;
        } else {
            $this->logger->debug('No finished items were returned from the local DB queue.');
        }

        return $this;
    }

    public function removeSourceFiles()
    {
        if ($this->options['remove-source'] !== true) {
            return;
        }

        $this->logger->debug('Beginning source file removal.');

        // getting finished items
        $finished = LocalQueue::finished()->get();
        if ($finished->count() > 0) {
            $finished->each(function($item, $key) {

            });
        }
    }

    /**
     * Gets all unfiltered items from host sources and structures them into
     * tidy collections keyed by source path.
     *
     * @return Collection
     **/
    private function getSourceItems()
    {
        $sources = $this->arguments['source'];
        $items = new Collection;

        // casting `$sources` to an array to normalize data structure
        if (! is_array($sources)) {
            $sources = array($sources);
        }

        // retrieve all items from sources and build collections
        foreach ($sources as $source) {
            // instatiate a new Finder instance
            $finder = new Finder();
            $hostItems = $finder->depth('== 0');

            // constrain search to source directory
            $hostItems->in("ssh2.sftp://$this->sshSession" . $source);

            // TODO: reject items that do not pass an optional cutoff date

            // collect the source items
            $collectedHostItems = Collection::make(iterator_to_array($hostItems, false));

            // place source items into main items collection
            $items->put($source, $collectedHostItems);
        }

        return $items;
    }

    /**
     * Filter host source items to remove anything already seen and tracked by
     * the local queue. Attempts to prevent rejection of items sharing the same
     * name by hashing the name with the mod time of the item.
     *
     * @param Collection $items
     * 
     * @return Collection
     **/
    private function filterSourceItems($items)
    {
        // first, check if local queue has ANY items at all
        $count = LocalQueue::count();
        if ($count == 0) {
            return $items;
        }

        // retreive all items from the local queue
        $seen = LocalQueue::lists('hash');

        $items->transform(function(&$sourceItems, $sourceDir) use ($seen) {
            return $sourceItems->reject(function($item) use ($seen) {
                if (
                    // TODO: filter out items that don't meet a date criteria
                    $item->getMTime() > strtotime($this->options['newer-than'])
                ) {
                    // filter out items seen in the local queue
                    return $seen->contains(
                        $this->makeHash($item->getBasename(), $item->getMTime())
                    );
                }
            });
        });

        return $items;
    }

    /**
     * Builds a final transfer list by 'zipping' together items from multiple
     * sources into an alternating list and limiting to available slots.
     *
     * @param Collection $items A full list of sources/items
     * @param int $slots Available transfer slots
     *
     * @return Collection
     **/
    private function buildTransferList($items, $slots)
    {
        $zippedItems = new Collection;

        // simple reduction to prevent null listings
        $items->map(function($sourceListing) use ($slots) {
            return $sourceListing->take($slots);
        });

        // TODO: add support for source-order priority

        // zip all sources together in alternating fashion
        for ($i=0; $i <= $slots; $i++) { 
            $items->each(function($sourceListing, $sourceDir) use ($zippedItems) {
                $zippedItems->push($sourceListing->shift());
            });
        }

        $cleanedItems = $zippedItems->take($slots)->filter();

        return $cleanedItems;
    }

    /**
     * Calculates the size and file count of an item.
     *
     * @param Finder $item
     *
     * @return array Item size and file count
     **/
    private function calculateItemSize(\Symfony\Component\Finder\SplFileInfo $item)
    {
        // file or dir specific data
        if ($item->isDir()) {
            $files = new Finder();

            // constrain search to source directory
            $files->in($item->getPath() . '/' . $item->getBasename());

            // calculate recursive sums
            $itemSize = 0;
            $fileCount = 0;
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $itemSize += $file->getSize();
                    $fileCount++;
                }
            }
        } elseif ($item->isFile()) {
            $itemSize = $item->getSize();
            $fileCount = 1;
        }

        return [
            'itemSize' => $itemSize,
            'fileCount' => $fileCount,
        ];
    }

    /**
     * Creates an MD5 hash for an item to assist with unique identification.
     *
     * @param string $name The item name
     * @param int $modeTime Last modified time in unix timestamp format
     * 
     * @return string MD5 Hash
     **/
    public function makeHash($name, $modTime)
    {
        $serial = serialize([$name, $modTime]);

        return md5($serial);
    }

    /**
     * A wrapping method to set global lftp limits on the host.
     *
     * Current limits supported: speed, queue items
     *
     * @return Locomotive
     **/
    public function setLimits()
    {
        $this->lftp
             ->setSpeedLimit($this->options['speed-limit'])
             ->setQueueTransferLimit($this->options['transfer-limit'])
             ->execute(false, $this->isLftpBackgrounded, $this->lftpTerminalId);

        return $this;
    }

    /**
     * Creates an SSH sFTP connection to the host server.
     *
     * Key file authentication requires both a public and private key. If a public
     * key file was not provided as an option, an attempt will still be made
     * with an assumption about the public key's location and name.
     *
     * @return Locomotive
     **/
    private function createSshSession()
    {
        $connection = ssh2_connect($this->arguments['host'], $this->options['port']);

        // handle connections w/ ssh key file
        if ($this->options['private-keyfile']) {
            $auth = @ssh2_auth_pubkey_file(
                $connection,
                $this->options['username'],
                $this->options['public-keyfile'] ?: $this->options['private-keyfile'] . '.pub',
                $this->options['private-keyfile']
            );
        // try with username and password
        } else {
            $auth = @ssh2_auth_password(
                $connection,
                $this->options['username'],
                $this->options['password']
            );
        }

        // test connection result
        if ($auth === false) {
            $this->logger->error('SSH connection attempt to host failed. Check your authentication settings and try again.');

            exit(1);
        } else {
            $this->sshSession = ssh2_sftp($connection);
            $this->logger->debug('SSH connection attempt succeeded.');
        }

        return $this;
    }

    /**
     * Gets the parsed arguments.
     *
     * @return Array
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * Gets the parsed options.
     *
     * @return ConsoleLogger
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Gets the console input.
     *
     * @return OutputInterface
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * Gets the console output.
     *
     * @return OutputInterface
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Gets the console logger.
     *
     * @return ConsoleLogger
     */
    public function getLogger()
    {
        return $this->logger;
    }
}
