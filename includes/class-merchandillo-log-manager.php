<?php

final class Merchandillo_Log_Manager implements Merchandillo_Log_Manager_Interface
{
    /** @var Merchandillo_Settings_Interface */
    private $settings;

    /** @var Merchandillo_Log_File_Store */
    private $fileStore;

    /** @var Merchandillo_Log_Entry_Reader */
    private $entryReader;

    /** @var Merchandillo_Log_Export_Builder */
    private $exportBuilder;

    public function __construct(Merchandillo_Settings_Interface $settings, string $source)
    {
        $this->settings = $settings;
        $this->fileStore = new Merchandillo_Log_File_Store($source);
        $this->entryReader = new Merchandillo_Log_Entry_Reader();
        $this->exportBuilder = new Merchandillo_Log_Export_Builder();
    }

    /**
     * @param array<string,mixed> $context
     */
    public function write(string $level, string $message, array $context = []): void
    {
        $settings = $this->settings->get();
        if ('1' !== (string) $settings['log_errors']) {
            return;
        }

        $normalizedLevel = in_array($level, $this->get_allowed_levels(), true)
            ? $level
            : 'error';

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log(
                $normalizedLevel,
                $message . (empty($context) ? '' : ' | ' . wp_json_encode($context)),
                ['source' => 'merchandillo-woocommerce-bridge']
            );
            return;
        }

        $line = '[merchandillo-woocommerce-bridge] ' . $message;
        if (!empty($context)) {
            $line .= ' | ' . wp_json_encode($context);
        }

        error_log($line);
    }

    /**
     * @return array<string,string>
     */
    public function get_files(): array
    {
        return $this->fileStore->get_files();
    }

    /**
     * @param array<string,string> $logFiles
     * @param array<string,mixed> $request
     * @return array{file:string,level:string,search:string,limit:int}
     */
    public function get_filters(array $logFiles, array $request): array
    {
        return $this->entryReader->get_filters($logFiles, $request);
    }

    /**
     * @return array<int,string>
     */
    public function get_allowed_levels(): array
    {
        return $this->entryReader->get_allowed_levels();
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
        return $this->entryReader->get_filtered_entries($logFiles, $selectedFile, $levelFilter, $searchFilter, $lineLimit);
    }

    /**
     * @return array{deleted:int,failed:int}
     */
    public function clear_files(): array
    {
        return $this->fileStore->clear_files();
    }

    /**
     * @param array{file:string,level:string,search:string,limit:int} $filters
     * @param array<int,array{file:string,timestamp:string,level:string,line:string}> $entries
     */
    public function build_export_text(array $filters, array $entries): string
    {
        return $this->exportBuilder->build_text($filters, $entries);
    }

    /**
     * @return array{file:string,timestamp:string,level:string,line:string}
     */
    public function normalize_entry(string $fileName, string $line): array
    {
        return $this->entryReader->normalize_entry($fileName, $line);
    }

    /**
     * @return array<int,string>
     */
    public function read_last_lines(string $filePath, int $lineLimit): array
    {
        return $this->entryReader->read_last_lines($filePath, $lineLimit);
    }

    public function format_file_option_label(string $fileName, string $path): string
    {
        return $this->fileStore->format_file_option_label($fileName, $path);
    }
}
