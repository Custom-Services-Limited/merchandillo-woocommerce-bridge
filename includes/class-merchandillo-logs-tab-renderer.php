<?php

final class Merchandillo_Logs_Tab_Renderer
{
    /** @var Merchandillo_Log_Manager_Interface */
    private $logManager;

    /** @var string */
    private $pageSlug;

    /** @var string */
    private $actionKey;

    /** @var string */
    private $nonceAction;

    /** @var Merchandillo_Logs_Tab_Filter_Form_Renderer */
    private $filterFormRenderer;

    /** @var Merchandillo_Logs_Tab_Table_Renderer */
    private $tableRenderer;

    public function __construct(
        Merchandillo_Log_Manager_Interface $logManager,
        string $pageSlug,
        string $actionKey,
        string $nonceAction,
        ?Merchandillo_Logs_Tab_Filter_Form_Renderer $filterFormRenderer = null,
        ?Merchandillo_Logs_Tab_Table_Renderer $tableRenderer = null
    ) {
        $this->logManager = $logManager;
        $this->pageSlug = $pageSlug;
        $this->actionKey = $actionKey;
        $this->nonceAction = $nonceAction;
        $this->filterFormRenderer = null === $filterFormRenderer
            ? new Merchandillo_Logs_Tab_Filter_Form_Renderer($logManager, $pageSlug, $actionKey, $nonceAction)
            : $filterFormRenderer;
        $this->tableRenderer = null === $tableRenderer ? new Merchandillo_Logs_Tab_Table_Renderer() : $tableRenderer;
    }

    /**
     * @param array<string,mixed> $request
     */
    public function render_tab(array $request): void
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

        $logsTabUrl = $this->get_page_url(['tab' => 'logs']);
        $lastHundredUrl = add_query_arg(
            [
                'tab' => 'logs',
                'log_file' => 'all',
                'log_level' => '',
                'log_search' => '',
                'log_limit' => '100',
            ],
            $logsTabUrl
        );
        $exportUrl = wp_nonce_url(
            add_query_arg(
                [
                    'tab' => 'logs',
                    $this->actionKey => 'export',
                    'log_file' => $filters['file'],
                    'log_level' => $filters['level'],
                    'log_search' => $filters['search'],
                    'log_limit' => (string) $filters['limit'],
                ],
                $logsTabUrl
            ),
            $this->nonceAction
        );

        echo '<div class="mwb-card">';
        $this->filterFormRenderer->render($filters, $logFiles, count($entries), $logsTabUrl, $lastHundredUrl, $exportUrl);
        $this->tableRenderer->render($entries);
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $extraArgs
     */
    private function get_page_url(array $extraArgs = []): string
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
