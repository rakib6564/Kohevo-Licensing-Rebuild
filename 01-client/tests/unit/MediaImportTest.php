<?php
/**
 * Unit tests for media library Phase 4 pieces that need no database: the public-address check for "Import from
 * URL" (Slate\Services\Media\RemoteFile), the piece size for chunked uploads (ChunkedUpload) and how audio files
 * are recognised (Media::isAudio, maxBytesFor, extForMime).
 */

declare(strict_types=1);

use Slate\Services\Media\ChunkedUpload;
use Slate\Services\Media\Media;
use Slate\Services\Media\RemoteFile;

unit('import from URL: only public http(s) addresses on the standard ports pass', function (): void {
    $public = static fn(string $h): array => ['93.184.215.14'];
    $ok = RemoteFile::vet('https://example.com/photo.jpg', $public);
    assert_true($ok['ok'], json_encode($ok));
    assert_eq(['example.com', 443, '93.184.215.14'], [$ok['host'], $ok['port'], $ok['ip']]);
    assert_true(RemoteFile::vet('http://example.com/a.mp3', $public)['ok'], 'plain http is allowed');

    $err = static fn(string $url, ?callable $r = null): ?string => RemoteFile::vet($url, $r ?? $public)['error'];
    assert_eq('http_only', $err('ftp://example.com/a.jpg'));
    assert_eq('http_only', $err('file:///etc/passwd'));
    assert_eq('http_only', $err('example.com/a.jpg'));
    assert_eq('credentials_in_url', $err('https://user:pass@example.com/a.jpg'));
    assert_eq('port_not_allowed', $err('https://example.com:8443/a.jpg'));
    assert_eq('port_not_allowed', $err('http://example.com:22/'));
    assert_eq('invalid_url', $err("https://example.com/a b.jpg"));
    assert_eq('invalid_url', $err(''));
    assert_eq('host_unresolvable', $err('https://nowhere.example/a.jpg', static fn(): array => []));
});

unit('import from URL: local, private and wrapped addresses are refused, whatever the name says', function (): void {
    foreach (['127.0.0.1', '10.0.0.5', '192.168.1.10', '172.16.4.4', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '64:ff9b::7f00:1', '2002:7f00:1::1'] as $ip) {
        assert_false(RemoteFile::isPublicIp($ip), $ip);
        $host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        assert_eq('private_address', RemoteFile::vet('http://' . $host . '/x.jpg')['error'] ?? null, 'literal ' . $ip);
        assert_eq('private_address', RemoteFile::vet('https://cdn.example/x.jpg', static fn(): array => [$ip])['error'] ?? null, 'resolved ' . $ip);
    }
    assert_eq('private_address', RemoteFile::vet('https://mixed.example/x.jpg', static fn(): array => ['93.184.215.14', '10.1.1.1'])['error'] ?? null, 'one private answer is enough to refuse');
    assert_true(RemoteFile::isPublicIp('8.8.8.8'));
    assert_true(RemoteFile::isPublicIp('2606:4700::6810:84e5'));
});

unit('import from URL: the file name comes from Content-Disposition; redirects resolve against the old address', function (): void {
    assert_eq('Report 2026.pdf', RemoteFile::dispositionName('attachment; filename="Report 2026.pdf"'));
    assert_eq('été.mp3', RemoteFile::dispositionName("attachment; filename*=UTF-8''%C3%A9t%C3%A9.mp3"));
    assert_eq('passwd', RemoteFile::dispositionName('attachment; filename="../../etc/passwd"'), 'no folders');
    assert_eq('', RemoteFile::dispositionName('inline'));
    assert_eq('https://cdn.example/b.jpg', RemoteFile::absolute('https://a.example/x/y.jpg', 'https://cdn.example/b.jpg'));
    assert_eq('https://a.example/b.jpg', RemoteFile::absolute('https://a.example/x/y.jpg', '/b.jpg'));
    assert_eq('https://a.example/x/b.jpg', RemoteFile::absolute('https://a.example/x/y.jpg', 'b.jpg'));
    assert_eq('http://cdn.example/b.jpg', RemoteFile::absolute('http://a.example/y.jpg', '//cdn.example/b.jpg'));
});

unit('chunked upload: the piece size fits under the server limits', function (): void {
    assert_eq(8 * 1024 * 1024, ChunkedUpload::iniBytes('8M'));
    assert_eq(2 * 1024 * 1024 * 1024, ChunkedUpload::iniBytes('2G'));
    assert_eq(512 * 1024, ChunkedUpload::iniBytes('512k'));
    assert_eq(1000, ChunkedUpload::iniBytes('1000'));
    assert_eq(0, ChunkedUpload::iniBytes(''));
    $size = ChunkedUpload::chunkSize();
    assert_true($size >= ChunkedUpload::MIN_CHUNK && $size <= ChunkedUpload::MAX_CHUNK, (string) $size);
    $limit = min(ChunkedUpload::iniBytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX, ChunkedUpload::iniBytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX);
    assert_true($limit <= ChunkedUpload::MIN_CHUNK || $size < $limit, 'a piece is smaller than the upload limit');
});

unit('audio: playable audio files are told apart from video and documents', function (): void {
    assert_true(Media::isAudio('audio/mpeg', 'mp3'));
    assert_true(Media::isAudio(['mime' => 'audio/x-wav', 'path' => '/uploads/media/a.WAV']));
    assert_true(Media::isAudio('video/mp4', 'm4a'), 'an m4a reported as video/mp4 is audio');
    assert_false(Media::isAudio('video/mp4', 'mp4'));
    assert_false(Media::isAudio('audio/mpeg', 'pdf'), 'the extension has to fit too');
    assert_false(Media::isVideo('video/mp4', 'm4a'));
    assert_true(in_array('mp3', Media::allowedExts(), true) && in_array('audio/mpeg', Media::allowedMimes(), true));
    assert_eq(Media::AUDIO_MAX_BYTES, Media::maxBytesFor('Episode 12.mp3'));
    assert_eq(Media::VIDEO_MAX_BYTES, Media::maxBytesFor('clip.MOV'));
    assert_eq(10 * 1024 * 1024, Media::maxBytesFor('photo.jpg'));
    assert_eq('mp3', Media::extForMime('audio/mpeg'));
    assert_eq('jpg', Media::extForMime('image/jpeg'));
    assert_eq('', Media::extForMime('text/html'), 'not a library type');
});
