<?php

final class Merchandillo_Log_Entry_Reader
{
    /** @var Merchandillo_Log_Filter_Sanitizer */
    private $filterSanitizer;

    /** @var Merchandillo_Log_Line_Parser */
    private $lineParser;

    /** @var Merchandillo_Log_Tail_Reader */
    private $tailReader;

    public function __construct(
        ?Merchandillo_Log_Filter_Sanitizer $filterSanitizer = null,
        ?Merchandillo_Log_Line_Parser $lineParser = null,
        ?Merchandillo_Log_Tail_Reader $tailReader = null
    ) {
        $this->filterSanitizer = null === $filterSanitizer ? new Merchandillo_Log_Filter_Sanitizer() : $filterSanitizer;
        $this->lineParser = null === $lineParser ? new Merchandillo_Log_Line_Parser() : $lineParser;
        $this->tailReader = null === $tailReader ? new Merchandillo_Log_Tail_Reader() : $tailReader;
    }

    /**
     * @param array<string,string> $logFiles
     * @param array<string,mixed> $request
     * @return array{file:string,level:string,search:string,limit:int}
     */
    public function get_filters(array $logFiles, array $request): array
    {
        return $this->filterSanitizer->sanitize($logFiles, $request);
    }

    /**
     * @return array<int,string>
     */
    public function get_allowed_levels(): array
    {
        return $this->filterSanitizer->get_allowed_levels();
    }

    /**
     * @param array<string,string> $logFiles
     * @return array<int,array{file:string,timestamp:string,level:string,line:string}>
     */
    public function get_filtered_entries(
        array $logFiles,
        string $selectedFile,
        string $levelFilter,
        string $searchFilter,
        int $lineLimit
    ): array {
        $targetFiles = $this->resolve_target_log_files($logFiles, $selectedFile);
        if (empty($targetFiles) || $lineLimit <= 0) {
            return [];
        }

        $entries = [];
        $sampleSize = max(500, $lineLimit * 8);
        foreach ($targetFiles as $fileName => $path) {
            $entries = array_merge(
                $entries,
                $this->collect_file_entries($fileName, $path, $sampleSize, $levelFilter, $searchFilter)
            );
        }

        if (count($entries) > $lineLimit) {
            $entries = array_slice($entries, -$lineLimit);
        }

        return $entries;
    }

    /**
     * @return array{file:string,timestamp:string,level:string,line:string}
     */
    public function normalize_entry(string $fileName, string $line): array
    {
        return $this->lineParser->normalize_entry($fileName, $line);
    }

    /**
     * @return array<int,string>
     */
    public function read_last_lines(string $filePath, int $lineLimit): array
    {
        return $this->tailReader->read_last_lines($filePath, $lineLimit);
    }

    /**
     * @param array<string,string> $logFiles
     * @return array<string,string>
     */
    private function resolve_target_log_files(array $logFiles, string $selectedFile): array
    {
        if ('all' === $selectedFile) {
            return array_reverse($logFiles, true);
        }

        if (!isset($logFiles[$selectedFile])) {
            return [];
        }

        return [$selectedFile => $logFiles[$selectedFile]];
    }

    /**
     * @return array<int,array{file:string,timestamp:string,level:string,line:string}>
     */
    private function collect_file_entries(
        string $fileName,
        string $path,
        int $sampleSize,
        string $levelFilter,
        string $searchFilter
    ): array {
        $entries = [];
        $lines = $this->read_last_lines($path, $sampleSize);

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ('' === $line) {
                continue;
            }

            $entry = $this->normalize_entry($fileName, $line);
            if ('' !== $levelFilter && $entry['level'] !== $levelFilter) {
                continue;
            }

            if ('' !== $searchFilter && false === stripos($entry['line'], $searchFilter)) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }
}
