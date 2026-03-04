<?php

final class Merchandillo_Log_Line_Parser
{
    /**
     * @return array{file:string,timestamp:string,level:string,line:string}
     */
    public function normalize_entry(string $fileName, string $line): array
    {
        return [
            'file' => $fileName,
            'timestamp' => $this->extract_timestamp($line),
            'level' => $this->extract_level($line),
            'line' => $line,
        ];
    }

    private function extract_timestamp(string $line): string
    {
        if (!preg_match('/^\[?([0-9]{4}-[0-9]{2}-[0-9]{2}[^\]\s]*)\]?/', $line, $match)) {
            return '';
        }

        return (string) ($match[1] ?? '');
    }

    private function extract_level(string $line): string
    {
        if (!preg_match('/\b(emergency|alert|critical|error|warning|notice|info|debug)\b/i', $line, $match)) {
            return '';
        }

        return strtolower((string) ($match[1] ?? ''));
    }
}
