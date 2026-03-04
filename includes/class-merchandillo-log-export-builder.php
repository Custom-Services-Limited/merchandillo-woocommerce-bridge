<?php

final class Merchandillo_Log_Export_Builder
{
    /**
     * @param array{file:string,level:string,search:string,limit:int} $filters
     * @param array<int,array{file:string,timestamp:string,level:string,line:string}> $entries
     */
    public function build_text(array $filters, array $entries): string
    {
        $output = "Merchandillo WooCommerce Bridge Logs\n";
        $output .= 'Generated (UTC): ' . gmdate('c') . "\n";
        $output .= 'File filter: ' . ('all' === $filters['file'] ? 'all files' : $filters['file']) . "\n";
        $output .= 'Level filter: ' . ('' === $filters['level'] ? 'all' : $filters['level']) . "\n";
        $output .= 'Search filter: ' . ('' === $filters['search'] ? '-' : $filters['search']) . "\n";
        $output .= 'Line limit: ' . (string) $filters['limit'] . "\n\n";

        if (empty($entries)) {
            return $output . "No log entries matched the current filters.\n";
        }

        foreach ($entries as $entry) {
            $level = '' === $entry['level'] ? 'UNKNOWN' : strtoupper($entry['level']);
            $output .= '[' . $entry['file'] . '] ';
            if ('' !== $entry['timestamp']) {
                $output .= '[' . $entry['timestamp'] . '] ';
            }
            $output .= '[' . $level . '] ' . $entry['line'] . "\n";
        }

        return $output;
    }
}
