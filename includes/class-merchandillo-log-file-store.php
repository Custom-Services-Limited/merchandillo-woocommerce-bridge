<?php

final class Merchandillo_Log_File_Store
{
    /** @var string */
    private $source;

    public function __construct(string $source)
    {
        $this->source = $source;
    }

    /**
     * @return array<string,string>
     */
    public function get_files(): array
    {
        $paths = [];
        $uploadDir = wp_upload_dir();
        $baseDir = isset($uploadDir['basedir']) ? (string) $uploadDir['basedir'] : '';

        if ('' !== $baseDir) {
            $logDir = trailingslashit($baseDir) . 'wc-logs';
            if (is_dir($logDir)) {
                $matches = glob(trailingslashit($logDir) . $this->source . '-*.log');
                if (is_array($matches)) {
                    foreach ($matches as $match) {
                        if (is_string($match)) {
                            $paths[] = $match;
                        }
                    }
                }
            }
        }

        if (function_exists('wc_get_log_file_path')) {
            $activePath = (string) wc_get_log_file_path($this->source);
            if ('' !== $activePath) {
                $paths[] = $activePath;
            }
        }

        $files = [];
        foreach (array_values(array_unique($paths)) as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }

            $fileName = basename($path);
            $files[$fileName] = $path;
        }

        uksort(
            $files,
            static function (string $left, string $right) use ($files): int {
                $leftPath = isset($files[$left]) ? $files[$left] : '';
                $rightPath = isset($files[$right]) ? $files[$right] : '';
                $leftTime = '' === $leftPath ? 0 : (int) filemtime($leftPath);
                $rightTime = '' === $rightPath ? 0 : (int) filemtime($rightPath);

                if ($leftTime === $rightTime) {
                    return strcmp($right, $left);
                }

                return $rightTime <=> $leftTime;
            }
        );

        return $files;
    }

    /**
     * @return array{deleted:int,failed:int}
     */
    public function clear_files(): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($this->get_files() as $path) {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
        ];
    }

    public function format_file_option_label(string $fileName, string $path): string
    {
        $size = is_file($path) ? size_format((int) filesize($path)) : '0 B';
        $updatedAt = is_file($path) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) filemtime($path)) : '';
        if ('' === $updatedAt) {
            return $fileName . ' (' . $size . ')';
        }

        return $fileName . ' (' . $size . ', ' . $updatedAt . ')';
    }
}
