<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2018/9/6
 * Time: 17:32
 */

namespace Fabloox;

use \Exception;
use \Redis;

ini_set('display_errors', 'on');
error_reporting(E_ALL);
if (function_exists('opcache_reset'))
{
    opcache_reset();
}


class Fabloox
{

    const VERSION = '1.1.1';


    const STATUS_STARTING = 1;


    const STATUS_RUNNING = 2;


    const STATUS_SHUTDOWN = 4;


    const STATUS_RELOADING = 8;


    const KILL_WORKER_TIMER_TIME = 2;

    public $id = 0;


    public $name = 'none';


    public $count = 1;


    public $user = '';


    public $group = '';

    public $reloadable = true;

    public $stopping = false;


    public static $daemonize = false;


    public static $stdoutFile = '/dev/null';


    public static $pidFile = '';


    public static $logFile = '';



    public static $onMasterReload = null;


    public static $onMasterStop = null;


    public  $redis = null;

    //主进程任务
    public static $onMasterTask = null;
    //子进程任务
    public static $onWorkerTask = null;
    //子进程初始化处理
    public static $onWorkerInit = null;

    protected static $_masterPid = 0;


    public static $_daemonName = 'Fabloox';


    public static $_workers = array();


    public static $_pidMap = array();


    protected static $_pidsToRestart = array();


    public static $_idMap = array();


    protected static $_status = self::STATUS_STARTING;


    protected static $_maxWorkerNameLength = 12;


    protected static $_maxSocketNameLength = 12;


    protected static $_maxUserNameLength = 12;


    protected static $_startFile = '';

    protected static $_outputStream = null;


    protected static $_outputDecorated = null;


    public static function runAll()
    {
        static::checkSapiEnv();

        static::init();

        static::parseCommand();

        static::daemonize();

        static::initWorkers();

        static::installSignal();

        static::saveMasterPid();

        static::displayUI();

        static::forkWorkers();

        static::resetStd();

        static::monitorWorkers();
    }

    /**
     * Check sapi.
     *
     * @return void
     */
    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != "cli")
        {
            exit("only run in command line mode \n");
        }
    }

    /**
     * Init.
     *
     * @return void
     */
    protected static function init()
    {
        set_error_handler(function($code, $msg, $file, $line){
            static::safeEcho("$msg in file $file on line $line\n");
        });

        $backtrace        = debug_backtrace();
        static::$_startFile = $backtrace[count($backtrace) - 1]['file'];
        $unique_prefix = str_replace('/', '_', static::$_startFile);

        if (empty(static::$pidFile))
        {
            static::$pidFile = __DIR__ . "/$unique_prefix.pid";
        }

        if (empty(static::$logFile))
        {
            static::$logFile = __DIR__ . '/'. static::$_daemonName .'.log';
        }
        $log_file = (string)static::$logFile;
        if (!is_file($log_file))
        {
            touch($log_file);
            chmod($log_file, 0622);
        }

        static::$_status = static::STATUS_STARTING;

        static::setProcessTitle(self::$_daemonName. ': master process  start_file=' . static::$_startFile);

        static::initId();
    }

    /**
     * Init All worker instances.
     *
     * @return void
     */
    protected static function initWorkers()
    {
        foreach (static::$_workers as $worker)
        {
            if (empty($worker->name))
            {
                $worker->name = 'none';
            }

            $worker_name_length = strlen($worker->name);
            if (static::$_maxWorkerNameLength < $worker_name_length)
            {
                static::$_maxWorkerNameLength = $worker_name_length;
            }

            if (empty($worker->user))
            {
                $worker->user = static::getCurrentUser();
            }
            else
            {
                if (posix_getuid() !== 0 && $worker->user != static::getCurrentUser())
                {
                    static::log('Warning: You must have the root privileges to change uid and gid.');
                }
            }

            // Get maximum length of unix user name.
            $user_name_length = strlen($worker->user);
            if (static::$_maxUserNameLength < $user_name_length)
            {
                static::$_maxUserNameLength = $user_name_length;
            }
        }
    }

    /**
     * Get all worker instances.
     *
     * @return array
     */
    public static function getAllWorkers()
    {
        return static::$_workers;
    }



    /**
     * Init idMap.
     * return void
     */
    protected static function initId()
    {
        foreach (static::$_workers as $worker_id => $worker)
        {
            $new_id_map = array();
            $worker->count = $worker->count <= 0 ? 1 : $worker->count;

            for($key = 0; $key < $worker->count; $key++)
            {
                $new_id_map[$key] = isset(static::$_idMap[$worker_id][$key]) ? static::$_idMap[$worker_id][$key] : 0;
            }

            static::$_idMap[$worker_id] = $new_id_map;
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());

        return $user_info['name'];
    }

    /**
     * Display staring UI.
     *
     * @return void
     */
    protected static function displayUI()
    {
        global $argv;

        static::safeEcho("<n>-----------------------<w> ".static::$_daemonName." </w>-----------------------------</n>\r\n");
        static::safeEcho(static::$_daemonName . ' version:'. static::VERSION. "          PHP version:". PHP_VERSION. "\r\n");
        static::safeEcho("------------------------<w> WORKERS </w>-------------------------------\r\n");

        static::safeEcho("<w>user</w>". str_pad('',
                static::$_maxUserNameLength + 2 - strlen('user')). "<w>worker</w>". str_pad('',
                static::$_maxWorkerNameLength + 2 - strlen('worker')). "<w>listen</w>". str_pad('',
                static::$_maxSocketNameLength + 2 - strlen('listen')). "<w>processes</w> <w>status</w>\n");
        foreach (static::$_workers as $worker)
        {
            static::safeEcho(str_pad($worker->user, static::$_maxUserNameLength + 2). str_pad($worker->name,
                    static::$_maxWorkerNameLength + 2). str_pad($worker->name,
                    static::$_maxSocketNameLength + 2). str_pad(' ' . $worker->count, 9). " <g> [OK] </g>\n");
        }
        static::safeEcho("----------------------------------------------------------------\n");
        if (static::$daemonize)
        {
            static::safeEcho("Input \"php $argv[0] stop\" to stop. Start success.\n\n");
        }
        else
        {
            static::safeEcho("Press Ctrl+C to stop. Start success.\n");
        }
    }

    /**
     * Parse command.
     *
     * @return void
     */
    protected static function parseCommand()
    {
        global $argv;
        // Check argv;
        $start_file = $argv[0];
        $available_commands = array(
            'start',
            'stop',
            'restart',
            'reload'
        );
        $usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\nconnections\tGet worker connections.\n";
        if (!isset($argv[1]) || !in_array($argv[1], $available_commands))
        {
            if (isset($argv[1]))
            {
                static::safeEcho('Unknown command: ' . $argv[1] . "\n");
            }
            exit($usage);
        }

        // Get command.
        $command  = trim($argv[1]);//start
        $command2 = isset($argv[2]) ? $argv[2] : '';//-d

        // Start command.
        $mode = '';
        if ($command === 'start')
        {
            if ($command2 === '-d' || static::$daemonize)
            {
                $mode = 'in DAEMON mode';
            } else {
                $mode = 'in DEBUG mode';
            }
        }

        static::log(self::$_daemonName. "[$start_file] $command $mode");

        // Get master process PID.
        $master_pid      = is_file(static::$pidFile) ? file_get_contents(static::$pidFile) : 0;
        $master_is_alive = $master_pid && posix_kill($master_pid, 0) && posix_getpid() != $master_pid;

        // Master is still alive?
        if ($master_is_alive)
        {
            if ($command === 'start')
            {
                static::log(self::$_daemonName. "[$start_file] already running");
                exit;
            }
        }
        elseif ($command !== 'start' && $command !== 'restart')
        {
            static::log(self::$_daemonName. "[$start_file] not run");
            exit;
        }

        // execute command.
        switch ($command)
        {
            case 'start':
                if ($command2 === '-d')
                {
                    static::$daemonize = true;
                }
                break;
            case 'restart':
            case 'stop':

                // Send stop signal to master process.
                $master_pid && posix_kill($master_pid, SIGINT);
                // Timeout.
                $timeout    = 10;
                $start_time = time();
                while (1)
                {
                    $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                    if ($master_is_alive)
                    {
                        // Timeout?
                        if (time() - $start_time >= $timeout)
                        {
                            static::log(self::$_daemonName. "[$start_file] stop fail");
                            exit;
                        }
                        // Waiting amoment.
                        usleep(100000);
                        continue;
                    }
                    // Stop success.
                    static::log(self::$_daemonName. "[$start_file] stop success");
                    if ($command === 'stop')
                    {
                        exit(0);
                    }
                    if ($command2 === '-d')
                    {
                        static::$daemonize = true;
                    }
                    break;
                }
                break;
            case 'reload':
                posix_kill($master_pid, SIGUSR1);
                exit;
            default :
                if (isset($command))
                {
                    static::safeEcho('Unknown command: ' . $command . "\n");
                }
                exit($usage);
        }
    }


    /**
     * Install signal handler.
     *
     * @return void
     */
    protected static function installSignal()
    {
        // stop
        pcntl_signal(SIGINT, array('\Fabloox\Fabloox', 'signalHandler'), false);
        // reload
        pcntl_signal(SIGUSR1, array('\Fabloox\Fabloox', 'signalHandler'), false);
        // graceful reload
        pcntl_signal(SIGQUIT, array('\Fabloox\Fabloox', 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * Reinstall signal handler.
     *
     * @return void
     */
    protected static function reinstallSignal()
    {
        // uninstall stop signal handler
        pcntl_signal(SIGINT, SIG_IGN, false);
        // uninstall reload signal handler
        pcntl_signal(SIGUSR1, SIG_IGN, false);
        // uninstall graceful reload signal handler
        pcntl_signal(SIGQUIT, SIG_IGN, false);
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        switch ($signal)
        {
            // Stop.
            case SIGINT:
                static::stopAll();
                break;
            case SIGQUIT:
            case SIGUSR1:
                static::$_pidsToRestart = static::getAllWorkerPids();
                static::reload();
                break;
        }
    }

    /**`
     * Run as deamon mode.
     *
     * @throws Exception
     */
    protected static function daemonize()
    {
        if (!static::$daemonize)
        {
            return;
        }

        umask(0);
        $pid = pcntl_fork();
        if (-1 === $pid)
        {
            throw new Exception('fork fail');
        }
        elseif ($pid > 0)
        {
            exit(0);
        }

        if (-1 === posix_setsid())
        {
            throw new Exception("setsid fail");
        }

        $pid = pcntl_fork();
        if (-1 === $pid)
        {
            throw new Exception("fork fail");
        }
        elseif (0 !== $pid)
        {
            exit(0);
        }
    }

    /**
     * Redirect standard input and output.
     *
     * @throws Exception
     */
    public static function resetStd()
    {
        if (!static::$daemonize)
        {
            return;
        }

        global $STDOUT, $STDERR;
        $handle = fopen(static::$stdoutFile, "a");
        if ($handle)
        {
            unset($handle);
            set_error_handler(function(){});
            fclose($STDOUT);
            fclose($STDERR);
            fclose(STDOUT);
            fclose(STDERR);

            $STDOUT = fopen(static::$stdoutFile, "a");
            $STDERR = fopen(static::$stdoutFile, "a");
            // change output stream
            static::$_outputStream = null;
            static::outputStream($STDOUT);
            restore_error_handler();
        }
        else
        {
            throw new Exception('can not open stdoutFile ' . static::$stdoutFile);
        }
    }

    /**
     * Save pid.
     *
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        static::$_masterPid = posix_getpid();

        if (false === file_put_contents(static::$pidFile, static::$_masterPid))
        {
            throw new Exception('can not save pid to ' . static::$pidFile);
        }

    }


    /**
     * Get all pids of worker processes.
     *
     * @return array
     */
    protected static function getAllWorkerPids()
    {
        $pid_array = array();
        foreach (static::$_pidMap as $worker_pid_array)
        {
            foreach ($worker_pid_array as $worker_pid)
            {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    protected static function forkWorkers()
    {
        foreach (static::$_workers as $worker)
        {
            if (static::$_status === static::STATUS_STARTING)
            {
                $worker_name_length = strlen($worker->name);
                if (static::$_maxWorkerNameLength < $worker_name_length)
                {
                    static::$_maxWorkerNameLength = $worker_name_length;
                }
            }

            while (count(static::$_pidMap[$worker->workerId]) < $worker->count)
            {
                static::forkOneWorkerForLinux($worker);
            }
        }
    }



    /**
     * Fork one worker process.
     *
     * @param \Fabloox\Fabloox $worker
     * @throws Exception
     */
    protected static function forkOneWorkerForLinux($worker)
    {
        // Get available worker id.
        $id = static::getId($worker->workerId, 0);
        if ($id === false)
        {
            return;
        }

        $pid = pcntl_fork();
        // For master process.
        if ($pid > 0)
        {//父进程此时，为守护进程
            static::$_pidMap[$worker->workerId][$pid] = $pid;
            static::$_idMap[$worker->workerId][$id]   = $pid;

        } // For child processes.
        elseif (0 === $pid)
        {

            if (static::$_status === static::STATUS_STARTING)
            {
                static::resetStd();
            }

            static::$_pidMap  = array();

            foreach(static::$_workers as $key => $one_worker)
            {
                if ($one_worker->workerId !== $worker->workerId)
                {
                    unset(static::$_workers[$key]);
                }
            }

            static::setProcessTitle(self::$_daemonName. ': worker process  ' . $worker->name);
            $worker->setUserAndGroup();
            $worker->id = $id;

            if (static::$onWorkerInit)
            {
                try
                {
                    call_user_func(static::$onWorkerInit, $worker);
                }
                catch (Exception $e)
                {
                    static::log($e);
                }
                catch (\Error $e)
                {
                    static::log($e);
                }
            }

            $worker->run($worker);
            exit(250);
        }
        else
        {
            throw new Exception("forkOneWorker fail");
        }

    }

    /**
     * Get worker id.
     *
     * @param int $worker_id
     * @param int $pid
     *
     * @return integer
     */
    protected static function getId($worker_id, $pid)
    {
        return array_search($pid, static::$_idMap[$worker_id]);
    }

    /**
     * Set unix user and group for current process.
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        // Get uid.
        $user_info = posix_getpwnam($this->user);
        if (!$user_info)
        {
            static::log("Warning: User {$this->user} not exsits");
            return;
        }

        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group)
        {
            $group_info = posix_getgrnam($this->group);
            if (!$group_info)
            {
                static::log("Warning: Group {$this->group} not exsits");
                return;
            }
            $gid = $group_info['gid'];
        }
        else
        {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        if ($uid != posix_getuid() || $gid != posix_getgid())
        {
            if (!posix_setgid($gid) || !posix_initgroups($user_info['name'], $gid) || !posix_setuid($uid))
            {
                static::log("Warning: change gid or uid fail.");
            }
        }
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        set_error_handler(function(){});
        // >=php 5.5
        if (function_exists('cli_set_process_title'))
        {
            cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle'))
        {
            setproctitle($title);
        }

        restore_error_handler();
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkers()
    {
        static::monitorWorkersForLinux();
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkersForLinux()
    {
        static::$_status = static::STATUS_RUNNING;
        while (1)
        {
            pcntl_signal_dispatch();
            $status = 0;
            $pid    = pcntl_wait($status, WUNTRACED);
            pcntl_signal_dispatch();
            if ($pid > 0)
            {
                // Find out witch worker process exited.
                foreach (static::$_pidMap as $worker_id => $worker_pid_array)
                {
                    if (isset($worker_pid_array[$pid]))
                    {
                        $worker = static::$_workers[$worker_id];
                        if ($status !== 0)
                        {
                            static::log("worker[" . $worker->name . ":$pid] exit with status $status");
                        }
                        unset(static::$_pidMap[$worker_id][$pid]);
                        $id                              = static::getId($worker_id, $pid);
                        static::$_idMap[$worker_id][$id] = 0;
                        break;
                    }
                }

                // Is still running state then fork a new worker process.
                if (static::$_status !== static::STATUS_SHUTDOWN)
                {
                    static::forkWorkers();
                    // If reloading continue.
                    if (isset(static::$_pidsToRestart[$pid]))
                    {
                        unset(static::$_pidsToRestart[$pid]);
                        static::reload();
                    }
                }
                else
                {
                    // If shutdown state and all child processes exited then master process exit.
                    if (!static::getAllWorkerPids())
                    {
                        static::exitAndClearAll();
                    }
                }

                sleep(1);
            }
            else
            {
                // If shutdown state and all child processes exited then master process exit.
                if (static::$_status === static::STATUS_SHUTDOWN && !static::getAllWorkerPids())
                {
                    static::exitAndClearAll();
                }
            }

        }
    }

    /**
     * Exit current process.
     *
     * @return void
     */
    protected static function exitAndClearAll()
    {
        @unlink(static::$pidFile);
        static::log(self::$_daemonName . "[" . basename(static::$_startFile) . "] has been stopped");
        if (static::$onMasterStop)
        {
            call_user_func(static::$onMasterStop);
        }
        exit(0);
    }

    /**
     * Execute reload.
     *
     * @return void
     */
    protected static function reload()
    {
        // For master process.
        if (static::$_masterPid === posix_getpid())
        {
            // Set reloading state.
            if (static::$_status !== static::STATUS_RELOADING && static::$_status !== static::STATUS_SHUTDOWN)
            {
                static::log(self::$_daemonName. "[" . basename(static::$_startFile) . "] reloading");
                static::$_status = static::STATUS_RELOADING;

                if (static::$onMasterReload)
                {
                    try {
                        call_user_func(static::$onMasterReload);
                    } catch (\Exception $e) {
                        static::log($e);
                        exit(250);
                    } catch (\Error $e) {
                        static::log($e);
                        exit(250);
                    }
                    static::initId();
                }
            }
            $sig = SIGUSR1;
            $reloadable_pid_array = array();
            foreach (static::$_pidMap as $worker_id => $worker_pid_array)
            {
                $worker = static::$_workers[$worker_id];
                if ($worker->reloadable)
                {
                    foreach ($worker_pid_array as $pid)
                    {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                }
                else
                {
                    foreach ($worker_pid_array as $pid)
                    {
                        posix_kill($pid, $sig);
                    }
                }
            }

            static::$_pidsToRestart = array_intersect(static::$_pidsToRestart, $reloadable_pid_array);

            if (empty(static::$_pidsToRestart))
            {
                if (static::$_status !== static::STATUS_SHUTDOWN)
                {
                    static::$_status = static::STATUS_RUNNING;
                }
                return;
            }
            $one_worker_pid = current(static::$_pidsToRestart);
            posix_kill($one_worker_pid, $sig);
        }
        else
        {
            reset(static::$_workers);
            $worker = current(static::$_workers);
            // Try to emit onWorkerReload callback.
            if ($worker->onWorkerReload)
            {
                try {
                    call_user_func($worker->onWorkerReload, $worker);
                } catch (\Exception $e) {
                    static::log($e);
                    exit(250);
                } catch (\Error $e) {
                    static::log($e);
                    exit(250);
                }
            }

            if ($worker->reloadable)
            {
                static::stopAll();
            }
        }
    }

    /**
     * Stop.
     *
     * @return void
     */
    public static function stopAll()
    {
        static::$_status = static::STATUS_SHUTDOWN;
        // For master process.
        if (static::$_masterPid === posix_getpid())
        {
            static::log(self::$_daemonName. "[" . basename(static::$_startFile) . "] stopping ...");
            $worker_pid_array = static::getAllWorkerPids();
            foreach ($worker_pid_array as $worker_pid)
            {
                posix_kill($worker_pid, SIGINT);
                posix_kill($worker_pid, SIGKILL);
            }
        } // For child processes.
        else
        {
            // Execute exit.
            foreach (static::$_workers as $worker)
            {
                if(!$worker->stopping)
                {
                    $worker->stop();
                    $worker->stopping = true;
                }
            }

        }
    }



    /**
     * Get process status.
     *
     * @return number
     */
    public static function getStatus()
    {
        return static::$_status;
    }



    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public static function checkErrors()
    {
        if (static::STATUS_SHUTDOWN != static::$_status)
        {
            $error_msg = 'Worker['. posix_getpid() .'] process terminated';
            $errors    = error_get_last();
            if ($errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                $error_msg .= ' with ERROR: ' . static::getErrorType($errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
            }
            static::log($error_msg);
        }
    }

    /**
     * Get error message by error code.
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch ($type) {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
    }

    /**
     * Log.
     *
     * @param string $msg
     * @return void
     */
    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (!static::$daemonize) {
            static::safeEcho($msg);
        }
        file_put_contents((string)static::$logFile, date('Y-m-d H:i:s') . ' ' . 'pid:'
            . (posix_getpid()) . ' ' . $msg, FILE_APPEND | LOCK_EX);
    }

    /**
     * Safe Echo.
     * @param $msg
     * @param bool $decorated
     * @return bool
     */
    public static function safeEcho($msg, $decorated = false)
    {
        $stream = static::outputStream();
        if (!$stream)
        {
            return false;
        }
        if (!$decorated)
        {
            $line = $white = $green = $end = '';
            if (static::$_outputDecorated)
            {
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            $msg = str_replace(array('<n>', '<w>', '<g>'), array($line, $white, $green), $msg);
            $msg = str_replace(array('</n>', '</w>', '</g>'), $end, $msg);
        }
        elseif (!static::$_outputDecorated)
        {
            return false;
        }
        fwrite($stream, $msg);
        fflush($stream);
        return true;
    }

    /**
     * @param null $stream
     * @return bool|resource
     */
    private static function outputStream($stream = null)
    {
        if (!$stream)
        {
            $stream = static::$_outputStream ? static::$_outputStream : STDOUT;
        }
        if (!$stream || !is_resource($stream) || 'stream' !== get_resource_type($stream))
        {
            return false;
        }
        $stat = fstat($stream);
        if (($stat['mode'] & 0170000) === 0100000)
        {
            // file
            static::$_outputDecorated = false;
        }
        else
        {
            static::$_outputDecorated =
                function_exists('posix_isatty') &&
                posix_isatty($stream);
        }
        return static::$_outputStream = $stream;
    }

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct()
    {
        $this->workerId                    = spl_object_hash($this);
        static::$_workers[$this->workerId] = $this;
        static::$_pidMap[$this->workerId]  = array();
    }





    /**
     * Run worker instance.
     *
     * @return void
     */
    public function run($worker)
    {
        static::$_status = static::STATUS_RUNNING;
//        register_shutdown_function(array("\\Fabloox\\Fabloox", 'checkErrors'));

        static::reinstallSignal();
        while (1)
        {
            if (static::$onWorkerTask)
            {
                try
                {
                    call_user_func(static::$onWorkerTask, $worker);
                }
                catch (\Exception $e)
                {
                    static::log($e);
                    exit(250);
                }
                catch (\Error $e)
                {
                    static::log($e);
                    exit(250);
                }
            }

            usleep(1000);
        }

        restore_error_handler();
    }

}

$fabloox = new Fabloox();
$fabloox->count = 3;
$fabloox->name = 'fabloox';

$fabloox::$onWorkerInit = function (Fabloox $worker)
{
    try
    {
        $worker->redis = new Redis();
        $worker->redis->connect('127.0.0.1', 6379);
        $worker->redis->select(10);
    }
    catch (Exception $e)
    {
        static::log($e);
        exit(250);
    }
    catch (\Error $e)
    {
        static::log($e);
        exit(250);
    }
};

$fabloox::$onWorkerTask = function (Fabloox $worker)
{

    while ($id = $worker->redis->lPop('mylist'))
    {
        file_put_contents('/tmp/log/'. posix_getpid(), $id . "\r\n",FILE_APPEND);
    }

};

Fabloox::runAll();
