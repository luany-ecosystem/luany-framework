<?php
// Minimal SMTP server for tests: php smtp-server.php <port-file> <out-file>
// Accepts one session (AUTH PLAIN/LOGIN accepted), writes the DATA to <out-file>.
[$_, $portFile, $outFile] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
file_put_contents($portFile, (string) $port);
$conn = stream_socket_accept($server, 15);
if (!$conn) { exit(1); }
$say = fn (string $line) => fwrite($conn, $line . "\r\n");
$say('220 test ESMTP');
$data = null; $auth = '';
while (($line = fgets($conn)) !== false) {
    $cmd = strtoupper(substr(trim($line), 0, 4));
    if ($data !== null) {
        if (rtrim($line, "\r\n") === '.') { file_put_contents($outFile, $auth . "\n" . $data); $data = null; $say('250 OK queued'); continue; }
        $data .= $line; continue;
    }
    switch ($cmd) {
        case 'EHLO': fwrite($conn, "250-test\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n"); break;
        case 'AUTH': $auth = trim($line); $say('235 Authenticated'); break;
        case 'DATA': $data = ''; $say('354 Go ahead'); break;
        case 'QUIT': $say('221 Bye'); fclose($conn); exit(0);
        default: $say('250 OK');
    }
}
