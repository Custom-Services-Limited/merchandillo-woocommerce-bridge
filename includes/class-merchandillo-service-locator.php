<?php

final class Merchandillo_Service_Locator
{
    /** @var string */
    private $optionName;

    /** @var string */
    private $cronHook;

    /** @var string */
    private $logSource;

    /** @var string */
    private $settingsPageSlug;

    /** @var string */
    private $logActionKey;

    /** @var string */
    private $logNonceAction;

    /** @var Merchandillo_Settings_Interface|null */
    private $settings = null;

    /** @var Merchandillo_Log_Manager_Interface|null */
    private $logManager = null;

    /** @var Merchandillo_Order_Payload_Builder_Interface|null */
    private $payloadBuilder = null;

    /** @var Merchandillo_Order_Sync_Service|null */
    private $syncService = null;

    /** @var Merchandillo_Api_Connection_Tester_Interface|null */
    private $apiConnectionTester = null;

    /** @var Merchandillo_Translation_Manager_Interface|null */
    private $translationManager = null;

    /** @var Merchandillo_Settings_Tab|null */
    private $settingsTab = null;

    /** @var Merchandillo_Logs_Tab|null */
    private $logsTab = null;

    /** @var Merchandillo_Admin_Page|null */
    private $adminPage = null;

    public function __construct(
        string $optionName,
        string $cronHook,
        string $logSource,
        string $settingsPageSlug,
        string $logActionKey,
        string $logNonceAction
    ) {
        $this->optionName = $optionName;
        $this->cronHook = $cronHook;
        $this->logSource = $logSource;
        $this->settingsPageSlug = $settingsPageSlug;
        $this->logActionKey = $logActionKey;
        $this->logNonceAction = $logNonceAction;
    }

    public function settings(): Merchandillo_Settings_Interface
    {
        if (null === $this->settings) {
            $this->settings = new Merchandillo_Settings($this->optionName);
        }

        return $this->settings;
    }

    public function log_manager(): Merchandillo_Log_Manager_Interface
    {
        if (null === $this->logManager) {
            $this->logManager = new Merchandillo_Log_Manager($this->settings(), $this->logSource);
        }

        return $this->logManager;
    }

    public function payload_builder(): Merchandillo_Order_Payload_Builder_Interface
    {
        if (null === $this->payloadBuilder) {
            $this->payloadBuilder = new Merchandillo_Order_Payload_Builder();
        }

        return $this->payloadBuilder;
    }

    public function sync_service(): Merchandillo_Order_Sync_Service
    {
        if (null === $this->syncService) {
            $this->syncService = new Merchandillo_Order_Sync_Service(
                $this->settings(),
                $this->log_manager(),
                $this->payload_builder(),
                $this->cronHook
            );
        }

        return $this->syncService;
    }

    public function api_connection_tester(): Merchandillo_Api_Connection_Tester_Interface
    {
        if (null === $this->apiConnectionTester) {
            $this->apiConnectionTester = new Merchandillo_Api_Connection_Tester(
                $this->settings(),
                $this->log_manager()
            );
        }

        return $this->apiConnectionTester;
    }

    public function translation_manager(): Merchandillo_Translation_Manager_Interface
    {
        if (null === $this->translationManager) {
            $this->translationManager = new Merchandillo_Translation_Manager($this->settings());
        }

        return $this->translationManager;
    }

    public function settings_tab(): Merchandillo_Settings_Tab
    {
        if (null === $this->settingsTab) {
            $this->settingsTab = new Merchandillo_Settings_Tab(
                $this->settings(),
                $this->settingsPageSlug,
                $this->logActionKey,
                $this->logNonceAction
            );
        }

        return $this->settingsTab;
    }

    public function logs_tab(): Merchandillo_Logs_Tab
    {
        if (null === $this->logsTab) {
            $this->logsTab = new Merchandillo_Logs_Tab(
                $this->log_manager(),
                $this->settingsPageSlug,
                $this->logActionKey,
                $this->logNonceAction
            );
        }

        return $this->logsTab;
    }

    public function admin_page(): Merchandillo_Admin_Page
    {
        if (null === $this->adminPage) {
            $this->adminPage = new Merchandillo_Admin_Page(
                $this->settings(),
                $this->settings_tab(),
                $this->logs_tab(),
                $this->api_connection_tester(),
                $this->settingsPageSlug,
                $this->logActionKey,
                $this->logNonceAction
            );
        }

        return $this->adminPage;
    }
}
