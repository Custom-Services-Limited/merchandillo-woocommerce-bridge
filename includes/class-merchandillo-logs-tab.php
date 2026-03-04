<?php

final class Merchandillo_Logs_Tab
{
    /** @var Merchandillo_Log_Manager_Interface */
    private $logManager;

    /** @var Merchandillo_Logs_Tab_Renderer */
    private $renderer;

    /** @var string */
    private $pageSlug;

    /** @var string */
    private $actionKey;

    /** @var string */
    private $nonceAction;

    public function __construct(
        Merchandillo_Log_Manager_Interface $logManager,
        string $pageSlug,
        string $actionKey,
        string $nonceAction
    ) {
        $this->logManager = $logManager;
        $this->pageSlug = $pageSlug;
        $this->actionKey = $actionKey;
        $this->nonceAction = $nonceAction;
        $this->renderer = new Merchandillo_Logs_Tab_Renderer($logManager, $pageSlug, $actionKey, $nonceAction);
    }

    /**
     * @param array<string,mixed> $request
     */
    public function render_notice(array $request): void
    {
        $cleared = isset($request['logs_cleared']) ? absint((string) wp_unslash($request['logs_cleared'])) : 0;
        $failed = isset($request['logs_failed']) ? absint((string) wp_unslash($request['logs_failed'])) : 0;
        if ($cleared <= 0 && $failed <= 0 && !isset($request['logs_cleared'])) {
            return;
        }

        if ($failed > 0) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo esc_html(
                sprintf(
                    __('Removed %1$d log file(s), but %2$d could not be deleted.', 'merchandillo-woocommerce-bridge'),
                    $cleared,
                    $failed
                )
            );
            echo '</p></div>';
            return;
        }

        if ($cleared > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(
                sprintf(
                    __('Removed %d log file(s).', 'merchandillo-woocommerce-bridge'),
                    $cleared
                )
            );
            echo '</p></div>';
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p>';
        echo esc_html__('No plugin log files were found to clear.', 'merchandillo-woocommerce-bridge');
        echo '</p></div>';
    }

    /**
     * @param array<string,mixed> $request
     */
    public function render_tab(array $request): void
    {
        $this->renderer->render_tab($request);
    }

    /**
     * @param array<string,mixed> $request
     */
    public function output_export(array $request): void
    {
        $logFiles = $this->logManager->get_files();
        $filters = $this->logManager->get_filters($logFiles, $request);
        $entries = $this->logManager->get_filtered_entries(
            $logFiles,
            $filters['file'],
            $filters['level'],
            $filters['search'],
            (int) $filters['limit']
        );

        $fileName = 'merchandillo-logs-' . gmdate('Ymd-His') . '.txt';
        nocache_headers();
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
        }

        echo $this->logManager->build_export_text($filters, $entries);
    }

    /**
     * @return array{deleted:int,failed:int}
     */
    public function clear_files(): array
    {
        return $this->logManager->clear_files();
    }

    /**
     * @param array<string,mixed> $extraArgs
     */
    public function get_page_url(array $extraArgs = []): string
    {
        return add_query_arg(
            array_merge(
                [
                    'page' => $this->pageSlug,
                ],
                $extraArgs
            ),
            admin_url('options-general.php')
        );
    }
}
