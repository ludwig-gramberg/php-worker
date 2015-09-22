<?php
// ini
ini_set('display_errors', true);
ini_set('html_errors', false);
ini_set('error_log', false);

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
                $processes = get_processes($cmd);
                foreach($processes as $pid) {
                    shell_exec('kill -'.$cfg[$worker_name]['kill_signal'].' '.$pid);
                }
            }
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
        echo "missing pidfile or pidfile argument\n";
        return false;
    }
    $mypid = getmypid();
    $pidfilepid = trim(file_get_contents($pidfile));
    $valid = $mypid == $pidfilepid;
    if($valid) {
        return true;
    }
    echo "pid mismatch $mypid, pidfile $pidfilepid\n";
}

/**
 * @param string $cfgfile
 * @return array
 */
function get_cfg($cfgfile) {
    if(!file_exists($cfgfile)) {
        echo "missing cfgfile $cfgfile\n";
        exit(1);
    }
    $cfg = parse_ini_file($cfgfile, true);
    if($cfg === false) {
        echo "invalid cfgfile syntax\n";
        exit(1);
    }
    return $cfg;
}

function get_processes($cmd) {
    $user = get_current_user();
    $processes = array();
    $lines = explode("\n", shell_exec('ps --no-headers -o "%p %u %a" -C php | grep '.escapeshellarg($user).' | grep '.escapeshellarg($cmd))); #  | grep '.escapeshellarg($cmd)
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
        $commands[$worker_name] = $php_executable.' -f '.$worker_cfg['path'];
    }
    return $commands;
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
        $processes = get_processes($cmd);
        while(count($processes) > $cfg[$worker_name]['instances']) {
            $pid = array_pop($processes);
            shell_exec('kill -'.$cfg[$worker_name]['kill_signal'].' '.$pid);
            $processes = get_processes($cmd);
        }
        while(count($processes) < $cfg[$worker_name]['instances']) {
            shell_exec($cmd.' >> '.escapeshellarg($cfg[$worker_name]['log']).' 2>&1 &');
            $processes = get_processes($cmd);
        }
    }

    echo "working\n";
    sleep(1);
}