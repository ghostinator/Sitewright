<?php
// Fake SMTP server for tests. php smtp_server.php <port> <plain|starttls> <user> <pass> <outfile> [pemfile]
// Records one JSON line per delivered message: {tls, auth_ok, user, from, to, data}.
[, $port, $mode, $user, $pass, $out, $pem] = $argv + [6 => ''];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $en, $es);
while ($c = @stream_socket_accept($srv, 60)) {
    $tls = false; $auth = false; $from = $to = ''; $data = ''; $authUser = '';
    $w = fn (string $s) => fwrite($c, $s . "\r\n");
    $w('220 fake ESMTP');
    while (($line = fgets($c)) !== false) {
        $line = rtrim($line, "\r\n"); $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO') { $w('250-fake'); $w('250-AUTH PLAIN LOGIN'); if ($mode === 'starttls' && !$tls) { $w('250-STARTTLS'); } $w('250 OK'); }
        elseif ($cmd === 'STAR') { $w('220 go ahead'); stream_context_set_option($c, 'ssl', 'local_cert', $pem); stream_context_set_option($c, 'ssl', 'verify_peer', false); $tls = @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER); }
        elseif (str_starts_with($line, 'AUTH PLAIN ')) { [, $u, $p] = explode("\0", (string)base64_decode(substr($line, 11))) + [null, '', '']; $authUser = $u; $auth = ($u === $user && $p === $pass); $w($auth ? '235 ok' : '535 bad credentials'); }
        elseif ($line === 'AUTH LOGIN') { $w('334 VXNlcm5hbWU6'); $u = base64_decode((string)fgets($c)); $w('334 UGFzc3dvcmQ6'); $p = base64_decode((string)fgets($c)); $authUser = $u; $auth = ($u === $user && $p === $pass); $w($auth ? '235 ok' : '535 bad credentials'); }
        elseif ($cmd === 'MAIL') { if ($user !== '' && !$auth) { $w('530 auth required'); continue; } $from = $line; $w('250 ok'); }
        elseif ($cmd === 'RCPT') { $to = $line; $w('250 ok'); }
        elseif ($cmd === 'DATA') { $w('354 go'); $data = ''; while (($l = fgets($c)) !== false) { if ($l === ".\r\n") { break; } $data .= $l; } file_put_contents($out, json_encode(['tls' => $tls, 'auth_ok' => $auth, 'user' => $authUser, 'from' => $from, 'to' => $to, 'data' => $data]) . "\n", FILE_APPEND); $w('250 queued'); }
        elseif ($cmd === 'QUIT') { $w('221 bye'); break; }
        else { $w('502 unknown'); }
    }
    fclose($c);
}
