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
            $parsedLine = $this->parse_line((string) $entry['line']);

            echo '<tr>';
            echo '<td><code>' . esc_html($timestamp) . '</code></td>';
            echo '<td><span class="mwb-level mwb-level-' . esc_attr($levelClassSuffix) . '">' . esc_html($level) . '</span></td>';
            echo '<td>';
            echo '<code class="mwb-log-line">' . esc_html($parsedLine['message']) . '</code>';
            if ('' !== $parsedLine['json']) {
                echo '<pre class="mwb-log-json" tabindex="0">' . esc_html($parsedLine['json']) . '</pre>';
            }
            echo '</td>';
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

    /**
     * @return array{message:string,json:string}
     */
    private function parse_line(string $line): array
    {
        $separatorPosition = strrpos($line, ' | ');
        if (false === $separatorPosition) {
            return [
                'message' => $line,
                'json' => '',
            ];
        }

        $message = substr($line, 0, $separatorPosition);
        $jsonContext = trim((string) substr($line, $separatorPosition + 3));
        if ('' === $jsonContext) {
            return [
                'message' => $line,
                'json' => '',
            ];
        }

        $decoded = json_decode($jsonContext, true);
        if (!is_array($decoded) || JSON_ERROR_NONE !== json_last_error()) {
            return [
                'message' => $line,
                'json' => '',
            ];
        }

        $normalized = $this->normalize_nested_json($decoded);
        $pretty = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $pretty || '' === $pretty) {
            return [
                'message' => $line,
                'json' => '',
            ];
        }

        return [
            'message' => $message,
            'json' => $pretty,
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalize_nested_json($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize_nested_json($item);
            }

            return $normalized;
        }

        if (!is_string($value)) {
            return $value;
        }

        $candidate = trim($value);
        if ('' === $candidate) {
            return $value;
        }

        $startsLikeJson = ('{' === $candidate[0] && '}' === substr($candidate, -1))
            || ('[' === $candidate[0] && ']' === substr($candidate, -1));
        if (!$startsLikeJson) {
            return $value;
        }

        $decoded = json_decode($candidate, true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            return $value;
        }

        return $this->normalize_nested_json($decoded);
    }
}
