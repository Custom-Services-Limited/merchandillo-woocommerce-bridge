<?php

final class Merchandillo_Log_Filter_Sanitizer
{
    /**
     * @param array<string,string> $logFiles
     * @param array<string,mixed> $request
     * @return array{file:string,level:string,search:string,limit:int}
     */
    public function sanitize(array $logFiles, array $request): array
    {
        $search = isset($request['log_search']) ? sanitize_text_field((string) wp_unslash($request['log_search'])) : '';

        return [
            'file' => $this->sanitize_file($logFiles, $request),
            'level' => $this->sanitize_level($request),
            'search' => $search,
            'limit' => $this->sanitize_limit($request),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function get_allowed_levels(): array
    {
        return ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    }

    /**
     * @param array<string,string> $logFiles
     * @param array<string,mixed> $request
     */
    private function sanitize_file(array $logFiles, array $request): string
    {
        $file = isset($request['log_file']) ? sanitize_file_name((string) wp_unslash($request['log_file'])) : 'all';
        if ('all' !== $file && !isset($logFiles[$file])) {
            return 'all';
        }

        return $file;
    }

    /**
     * @param array<string,mixed> $request
     */
    private function sanitize_level(array $request): string
    {
        $level = isset($request['log_level']) ? strtolower(sanitize_key((string) wp_unslash($request['log_level']))) : '';
        if ('' !== $level && !in_array($level, $this->get_allowed_levels(), true)) {
            return '';
        }

        return $level;
    }

    /**
     * @param array<string,mixed> $request
     */
    private function sanitize_limit(array $request): int
    {
        $limit = isset($request['log_limit']) ? absint((string) wp_unslash($request['log_limit'])) : 100;
        if ($limit <= 0) {
            $limit = 100;
        }

        return min($limit, 5000);
    }
}
