<?php

for ($port = 8000; $port <= 8100; $port++) {
    $errorCode = 0;
    $errorMessage = '';
    $socket = @stream_socket_server(
        'tcp://127.0.0.1:' . $port,
        $errorCode,
        $errorMessage,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
    );
    if (is_resource($socket)) {
        fclose($socket);
        echo $port;
        exit(0);
    }
}

fwrite(STDERR, '[FAILED] No available loopback port was found between 8000 and 8100.' . PHP_EOL);
exit(1);
