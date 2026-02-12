<?php

final class Merchandillo_Logs_Tab_Table_Renderer
{
    /**
     * @param array<int,array{file:string,timestamp:string,level:string,line:string}> $entries
     */
    public function render(array $entries): void
    {
        echo '<table class="widefat striped mwb-log-table">';
        echo '<thead><tr>';
        echo '<th style="width:21%;">' . esc_html__('Timestamp', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '<th style="width:10%;">' . esc_html__('Level', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '<th>' . esc_html__('Log Line', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '<th style="width:21%;">' . esc_html__('File', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($entries)) {
            echo '<tr><td colspan="4">' . esc_html__('No log entries matched the current filters.', 'merchandillo-woocommerce-bridge') . '</td></tr>';
            echo '</tbody>';
            echo '</table>';
            return;
        }

        foreach (array_reverse($entries) as $entry) {
            $timestamp = '' === $entry['timestamp'] ? '-' : $entry['timestamp'];
            $level = '' === $entry['level'] ? '-' : strtoupper($entry['level']);
            $levelClassSuffix = $this->sanitize_level_class((string) $entry['level']);

            echo '<tr>';
            echo '<td><code>' . esc_html($timestamp) . '</code></td>';
            echo '<td><span class="mwb-level mwb-level-' . esc_attr($levelClassSuffix) . '">' . esc_html($level) . '</span></td>';
            echo '<td><code style="white-space:pre-wrap;word-break:break-word;">' . esc_html($entry['line']) . '</code></td>';
            echo '<td><code>' . esc_html($entry['file']) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    private function sanitize_level_class(string $level): string
    {
        $levelClassSuffix = strtolower($level);
        $levelClassSuffix = preg_replace('/[^a-z0-9_\-]/', '', $levelClassSuffix);
        if (null === $levelClassSuffix || '' === $levelClassSuffix) {
            return 'unknown';
        }

        return $levelClassSuffix;
    }
}
