<?php

interface Merchandillo_Settings_Interface
{
    public function option_name(): string;

    /**
     * @return array<string,string>
     */
    public function defaults(): array;

    /**
     * @return array<string,string>
     */
    public function get(): array;

    /**
     * @param mixed $input
     * @return array<string,string>
     */
    public function sanitize($input): array;
}
