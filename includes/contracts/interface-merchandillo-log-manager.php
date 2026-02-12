<?php

interface Merchandillo_Log_Manager_Interface
{
    /**
     * @param array<string,mixed> $context
     */
    public function write(string $level, string $message, array $context = []): void;

    /**
     * @return array<string,string>
     */
    public function get_files(): array;

    /**
     * @param array<string,string> $logFiles
     * @param array<string,mixed> $request
     * @return array{file:string,level:string,search:string,limit:int}
     */
    public function get_filters(array $logFiles, array $request): array;

    /**
     * @return array<int,string>
     */
    public function get_allowed_levels(): array;

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
    ): array;

    /**
     * @return array{deleted:int,failed:int}
     */
    public function clear_files(): array;

    /**
     * @param array{file:string,level:string,search:string,limit:int} $filters
     * @param array<int,array{file:string,timestamp:string,level:string,line:string}> $entries
     */
    public function build_export_text(array $filters, array $entries): string;

    /**
     * @return array{file:string,timestamp:string,level:string,line:string}
     */
    public function normalize_entry(string $fileName, string $line): array;

    /**
     * @return array<int,string>
     */
    public function read_last_lines(string $filePath, int $lineLimit): array;

    public function format_file_option_label(string $fileName, string $path): string;
}
