<?php

namespace FlourishWooCommercePlugin\Handlers;

defined( 'ABSPATH' ) || exit;

class SettingsHandler
{
    private $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    // Retrieve a specific setting
    public function getSetting($key, $default = '')
    {
        return $this->existing_settings[$key] ?? $default;
    }

    // Retrieve all settings
    public function getAllSettings()
    {
        return $this->existing_settings;
    }
}



