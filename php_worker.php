<?php
ini_set('error_log', '/var/log/php_worker/init.log');

error_log('php_worker started');

// sig handling
declare(ticks = 1);

function worker_sig_handler($signo) {

    global $cfg;
    global $cfgfile;
    global $commands;

    switch($signo) {
        case SIGTERM:
        case SIGUSR1:
        case SIGINT:
        case SIGHUP:
            $cfg = $cfg ? $cfg : get_cfg($cfgfile);
            $commands = $commands ? $commands : get_commands($cfgfile);
            foreach($commands as $worker_name => $cmd) {

                $worker_id = get_worker_id($worker_name);
                $processes = get_processes($worker_id);

                foreach($processes as $pid) {
                    shell_exec('kill -'.$cfg[$worker_name]['kill_signal'].' '.$pid);
                }
            }
            error_log('php_worker stopped');
            exit(0);
            break;
    }
};

pcntl_signal(SIGTERM, 'worker_sig_handler');
pcntl_signal(SIGINT,  'worker_sig_handler');
pcntl_signal(SIGUSR1, 'worker_sig_handler');
pcntl_signal(SIGHUP,  'worker_sig_handler');

// params

$pidfile = array_key_exists(1, $_SERVER['argv']) ? $_SERVER['argv'][1] : null;
$cfgfile = array_key_exists(2, $_SERVER['argv']) ? $_SERVER['argv'][2] : null;

// helpers

/**
 * @param $pidfile
 * @return bool
 */
function is_pid_valid($pidfile) {
    if(!file_exists($pidfile)) {
        error_log("missing pidfile or pidfile argument");
        return false;
    }
    $mypid = getmypid();
    $pidfilepid = trim(file_get_contents($pidfile));
    $valid = $mypid == $pidfilepid;
    if($valid) {
        return true;
    }
    error_log("pid mismatch $mypid, pidfile $pidfilepid");
}

/**
 * @param string $cfgfile
 * @return array
 */
function get_cfg($cfgfile) {
    if(!file_exists($cfgfile)) {
        error_log("missing cfgfile $cfgfile");
        exit(1);
    }
    $cfg = parse_ini_file($cfgfile, true);
    if($cfg === false) {
        error_log("invalid cfgfile syntax");
        exit(1);
    }
    return $cfg;
}

function get_processes($id) {

    $proc = posix_getpwuid(posix_geteuid());
    $user = $proc['name'];

    $processes = array();
    $cmd = 'ps --no-headers -o "%p %u %a" -C php | grep '.escapeshellarg($user).' | grep '.escapeshellarg($id);

    $lines = explode("\n", shell_exec($cmd));
    $regex = '/^([0-9]+) .*$/';
    foreach($lines as $line) {
        if(preg_match($regex, trim($line), $m)) {
            $processes[] = $m[1];
        }
    }

    return $processes;
}

function get_commands($cfgfile) {
    $php_executable = trim(shell_exec('which php'));
    $cfg = get_cfg($cfgfile);
    $commands = array();
    foreach($cfg as $worker_name => $worker_cfg) {
        $commands[$worker_name] = $php_executable.' -f '.escapeshellarg($worker_cfg['path']);
    }
    return $commands;
}

function get_worker_id($worker_name) {
    return substr(sha1('php_worker:'.$worker_name),0,16);
}

// run

sleep(3); // short wait until we start to make sure pid is written by now

$cfg = get_cfg($cfgfile);
$commands = get_commands($cfgfile);

while(true) {
    if(!is_pid_valid($pidfile)) {
        exit;
    }

    foreach($commands as $worker_name => $cmd) {

        $worker_id = get_worker_id($worker_name);
        $processes = get_processes($worker_id);

        while(count($processes) > $cfg[$worker_name]['instances']) {
            $pid = array_pop($processes);
            error_log('too many processes of '.$worker_name.', stopping '.$pid);
            shell_exec('kill -'.$cfg[$worker_name]['kill_signal'].' '.$pid);
            $processes = get_processes($worker_id);
        }

        while(count($processes) < $cfg[$worker_name]['instances']) {
            error_log('not enough processes of '.$worker_name.', starting '.$cmd);
            shell_exec($cmd.' '.escapeshellarg($worker_id).' >> '.escapeshellarg($cfg[$worker_name]['log']).' 2>&1 &');
            $processes = get_processes($worker_id);
        }
    }

    sleep(1);
}