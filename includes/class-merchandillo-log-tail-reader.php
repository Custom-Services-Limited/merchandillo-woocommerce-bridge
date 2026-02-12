<?php

final class Merchandillo_Log_Tail_Reader
{
    /**
     * @return array<int,string>
     */
    public function read_last_lines(string $filePath, int $lineLimit): array
    {
        if ($lineLimit <= 0 || !is_readable($filePath)) {
            return [];
        }

        $handle = fopen($filePath, 'rb');
        if (false === $handle) {
            return [];
        }

        if (0 !== fseek($handle, 0, SEEK_END)) {
            fclose($handle);
            return [];
        }

        $fileSize = ftell($handle);
        if (false === $fileSize || $fileSize <= 0) {
            fclose($handle);
            return [];
        }

        $buffer = '';
        $position = 0;
        $chunkSize = 8192;

        while ($position < $fileSize && substr_count($buffer, "\n") <= $lineLimit) {
            $bytesToRead = min($chunkSize, $fileSize - $position);
            $position += $bytesToRead;

            if (0 !== fseek($handle, -$position, SEEK_END)) {
                break;
            }

            $chunk = fread($handle, $bytesToRead);
            if (false === $chunk || '' === $chunk) {
                break;
            }

            $buffer = $chunk . $buffer;
        }

        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $buffer);
        if (!is_array($lines)) {
            return [];
        }

        if (!empty($lines) && '' === end($lines)) {
            array_pop($lines);
        }

        if (empty($lines)) {
            return [];
        }

        return array_slice($lines, -$lineLimit);
    }
}
