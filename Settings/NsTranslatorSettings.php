<?php

namespace Modules\NsTranslator\Settings;

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Services\Helper;
use App\Services\SettingsPage;

class NsTranslatorSettings extends SettingsPage
{
    const IDENTIFIER = 'ns_translator';

    const AUTOLOAD = true;

    public function __construct()
    {
        $this->form = SettingForm::form(
            title: __m( 'Translator Settings', 'NsTranslator' ),
            description: __m( 'Configure the Ollama API connection used for translating localization files.', 'NsTranslator' ),
            tabs: SettingForm::tabs(
                $this->getOllamaSettings(),
                $this->getTranslationSettings(),
            )
        );
    }

    public function getOllamaSettings()
    {
        return SettingForm::tab(
            identifier: 'ollama',
            label: __m( 'Ollama API', 'NsTranslator' ),
            fields: SettingForm::fields(
                FormInput::text(
                    label: __m( 'Ollama Host', 'NsTranslator' ),
                    name: 'ns_translator_ollama_host',
                    value: ns()->option->get( 'ns_translator_ollama_host', 'http://host.docker.internal:11434' ),
                    validation: 'required',
                    description: __m( 'The base URL of the Ollama API server. Example: http://localhost:11434', 'NsTranslator' ),
                ),
                FormInput::text(
                    label: __m( 'Model', 'NsTranslator' ),
                    name: 'ns_translator_ollama_model',
                    value: ns()->option->get( 'ns_translator_ollama_model', 'llama3.1:8b' ),
                    validation: 'required',
                    description: __m( 'The Ollama model to use for translations. Example: llama3.1:8b, gemma2, mistral', 'NsTranslator' ),
                ),
                FormInput::number(
                    label: __m( 'Request Timeout (seconds)', 'NsTranslator' ),
                    name: 'ns_translator_ollama_timeout',
                    value: ns()->option->get( 'ns_translator_ollama_timeout', 120 ),
                    description: __m( 'Maximum time in seconds to wait for a response from Ollama.', 'NsTranslator' ),
                ),
            ),
        );
    }

    public function getTranslationSettings()
    {
        return SettingForm::tab(
            identifier: 'translation',
            label: __m( 'Translation', 'NsTranslator' ),
            fields: SettingForm::fields(
                FormInput::number(
                    label: __m( 'Batch Size', 'NsTranslator' ),
                    name: 'ns_translator_batch_size',
                    value: ns()->option->get( 'ns_translator_batch_size', 10 ),
                    description: __m( 'Number of strings to send in each translation request. Lower values are more reliable but slower.', 'NsTranslator' ),
                ),
                FormInput::select(
                    label: __m( 'Source Language', 'NsTranslator' ),
                    name: 'ns_translator_source_language',
                    value: ns()->option->get( 'ns_translator_source_language', 'en' ),
                    options: Helper::kvToJsOptions( $this->getLanguageOptions() ),
                    validation: 'required',
                    description: __m( 'The source language used as reference for translations.', 'NsTranslator' ),
                ),
                FormInput::switch(
                    label: __m( 'Skip Existing Translations', 'NsTranslator' ),
                    name: 'ns_translator_skip_existing',
                    value: ns()->option->get( 'ns_translator_skip_existing', 'yes' ),
                    options: Helper::kvToJsOptions( [
                        'yes' => __m( 'Yes', 'NsTranslator' ),
                        'no' => __m( 'No', 'NsTranslator' ),
                    ] ),
                    description: __m( 'If enabled, strings that already have a translation different from the source will be skipped.', 'NsTranslator' ),
                ),
            ),
        );
    }

    /**
     * Get available language options from config.
     */
    private function getLanguageOptions(): array
    {
        return config( 'nexopos.languages', [] );
    }
}
