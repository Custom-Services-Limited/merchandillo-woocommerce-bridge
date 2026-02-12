<?php

final class Merchandillo_Translation_Manager implements Merchandillo_Translation_Manager_Interface
{
    private const TEXT_DOMAIN = 'merchandillo-woocommerce-bridge';

    /** @var Merchandillo_Settings_Interface */
    private $settings;

    /** @var Merchandillo_Translation_Dictionary */
    private $dictionary;

    /** @var string|null */
    private $language = null;

    public function __construct(
        Merchandillo_Settings_Interface $settings,
        ?Merchandillo_Translation_Dictionary $dictionary = null
    ) {
        $this->settings = $settings;
        $this->dictionary = null === $dictionary ? new Merchandillo_Translation_Dictionary() : $dictionary;
    }

    public function register_hooks(): void
    {
        add_filter('gettext', [$this, 'translate_gettext'], 20, 3);
    }

    public function translate_gettext(string $translatedText, string $text, string $domain): string
    {
        if (self::TEXT_DOMAIN !== $domain) {
            return $translatedText;
        }

        if ('el' !== $this->current_language()) {
            return $translatedText;
        }

        $catalog = $this->dictionary->greek_catalog();
        if (!isset($catalog[$text])) {
            return $translatedText;
        }

        return $catalog[$text];
    }

    private function current_language(): string
    {
        if (null !== $this->language) {
            return $this->language;
        }

        $settings = $this->settings->get();
        $language = isset($settings['ui_language']) ? sanitize_key((string) $settings['ui_language']) : 'en';
        if (!in_array($language, ['en', 'el'], true)) {
            $language = 'en';
        }

        $this->language = $language;

        return $this->language;
    }
}
