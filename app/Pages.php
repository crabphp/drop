<?php

declare(strict_types=1);

namespace App;

use Crab\Http\Response;
use Psr\Clock\ClockInterface;

/** Plain string templates: no experimental view engine and no process-wide output buffers. */
final readonly class Pages
{
    public function __construct(private Settings $settings, private ClockInterface $clock) {}

    public function home(): Response
    {
        return $this->page('Share a file', $this->template('home', [
            'max_bytes' => (string) $this->settings->maxFileBytes,
        ]), fullscreen: true);
    }

    /** @param array<string, mixed> $file */
    public function download(array $file): Response
    {
        $expires = gmdate('c', (int) $file['expires_at']);
        return $this->page('Download a file', $this->template('download', [
            'name' => self::escape($file['name']), 'size' => self::bytes((int) $file['size']),
            'expires_iso' => $expires, 'remaining' => $this->remaining((int) $file['expires_at']),
            'id' => self::escape($file['id']),
        ]));
    }

    public function missing(): Response
    {
        return $this->page('File unavailable', $this->template('missing'), status: 404);
    }

    public function login(string $error = '', int $status = 200): Response
    {
        return $this->page('Backoffice', $this->template('login', [
            'error' => $error === '' ? '' : '<p class="error" role="alert">' . self::escape($error) . '</p>',
        ]), status: $status);
    }

    /** @param list<array<string, mixed>> $files */
    public function backoffice(array $files, int $page): Response
    {
        $more = count($files) > 50;
        $rows = '';
        foreach (array_slice($files, 0, 50) as $file) {
            $name = self::escape($file['name']);
            $url = self::escape($this->settings->origin() . '/' . $file['id']);
            $iso = gmdate('c', (int) $file['expires_at']);
            $rows .= '<tr><th scope="row" class="filename">' . $name . '</th>'
                . '<td class="num">' . self::bytes((int) $file['size']) . '</td>'
                . '<td class="nowrap"><time datetime="' . $iso . '" data-expires="' . $iso . '">' . $this->remaining((int) $file['expires_at']) . '</time></td>'
                . '<td><button type="button" data-copy="' . $url . '" aria-label="Copy link for ' . $name . '">Copy link</button></td></tr>';
        }
        $table = $rows === '' ? '<p class="empty">No files.</p>'
            : '<div class="table-wrap section"><table class="plain hover" aria-label="Current files"><thead><tr><th scope="col">File</th><th scope="col" class="num">Size</th><th scope="col">Expires in</th><th scope="col">Action</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $pagination = !$more && $page === 1 ? '' : '<nav class="pagination" aria-label="File pages">'
            . ($page > 1 ? '<a href="/backoffice?page=' . ($page - 1) . '">Previous</a>' : '')
            . '<span>Page ' . $page . '</span>'
            . ($more ? '<a href="/backoffice?page=' . ($page + 1) . '">Next</a>' : '') . '</nav>';
        return $this->page('Backoffice', $this->template('backoffice', ['files' => $table, 'pagination' => $pagination]), wide: true);
    }

    public static function bytes(int $size): string
    {
        if ($size < 1000) {
            return $size . ' B';
        }
        $unit = $size < 1000 ** 2 ? 'KB' : ($size < 1000 ** 3 ? 'MB' : 'GB');
        $divisor = match ($unit) { 'KB' => 1000, 'MB' => 1000 ** 2, default => 1000 ** 3 };
        return rtrim(rtrim(number_format($size / $divisor, 1, '.', ''), '0'), '.') . ' ' . $unit;
    }

    private function remaining(int $expires): string
    {
        $minutes = max(1, (int) ceil(($expires - $this->clock->now()->getTimestamp()) / 60));
        return $minutes >= 60 ? intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm' : $minutes . 'm';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Only pre-escaped text or application-owned markup enters these placeholders. @param array<string, string> $values */
    private function template(string $name, array $values = []): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/resources/views/' . $name . '.html');
        if ($source === false) {
            throw new \RuntimeException('The page template could not be read.');
        }
        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{{' . $key . '}}'] = $value;
        }
        return strtr($source, $replace);
    }

    private function page(string $title, string $content, int $status = 200, bool $wide = false, bool $fullscreen = false): Response
    {
        return Response::html($this->template('layout', [
            'title' => self::escape($title), 'content' => $content,
            'width' => $fullscreen ? 'full' : ($wide ? 'wide' : ''),
            'body_class' => $fullscreen ? 'upload-screen' : '',
            'header' => $fullscreen ? '' : '<header class="site-header"><a href="/" class="wordmark">drop<span>.</span></a></header>',
        ]), $status);
    }
}
