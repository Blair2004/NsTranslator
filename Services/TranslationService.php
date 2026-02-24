<?php

namespace Modules\NsTranslator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class TranslationService
{
    /**
     * Get the Ollama API host.
     */
    public function getHost(): string
    {
        return rtrim( ns()->option->get( 'ns_translator_ollama_host', 'http://host.docker.internal:11434' ), '/' );
    }

    /**
     * Get the Ollama model name.
     */
    public function getModel(): string
    {
        return ns()->option->get( 'ns_translator_ollama_model', 'llama3.1:8b' );
    }

    /**
     * Get the request timeout.
     */
    public function getTimeout(): int
    {
        return (int) ns()->option->get( 'ns_translator_ollama_timeout', 120 );
    }

    /**
     * Get the batch size for translation.
     */
    public function getBatchSize(): int
    {
        return (int) ns()->option->get( 'ns_translator_batch_size', 10 );
    }

    /**
     * Get the source language code.
     */
    public function getSourceLanguage(): string
    {
        return ns()->option->get( 'ns_translator_source_language', 'en' );
    }

    /**
     * Whether to skip existing translations.
     */
    public function shouldSkipExisting(): bool
    {
        return ns()->option->get( 'ns_translator_skip_existing', 'yes' ) === 'yes';
    }

    /**
     * Get available languages from config, excluding the source language.
     */
    public function getTargetLanguages(): array
    {
        $languages = config( 'nexopos.languages', [] );
        $source = $this->getSourceLanguage();

        unset( $languages[$source] );

        return $languages;
    }

    /**
     * Get the full language name for a code.
     */
    public function getLanguageName( string $code ): string
    {
        return config( 'nexopos.languages', [] )[$code] ?? $code;
    }

    /**
     * Get the path to the core lang directory.
     */
    public function getCoreLangPath(): string
    {
        return base_path( 'lang' );
    }

    /**
     * Get the path to a module's Lang directory.
     */
    public function getModuleLangPath( string $moduleNamespace ): string
    {
        return base_path( 'modules/' . $moduleNamespace . '/Lang' );
    }

    /**
     * Load translations from a JSON file.
     */
    public function loadTranslations( string $filePath ): array
    {
        if ( ! File::exists( $filePath ) ) {
            return [];
        }

        $content = File::get( $filePath );
        $decoded = json_decode( $content, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [];
        }

        return $decoded;
    }

    /**
     * Save translations to a JSON file.
     */
    public function saveTranslations( string $filePath, array $translations ): void
    {
        $directory = dirname( $filePath );

        if ( ! File::isDirectory( $directory ) ) {
            File::makeDirectory( $directory, 0755, true );
        }

        $json = json_encode( $translations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        File::put( $filePath, $json );
    }

    /**
     * Determine which strings need translation.
     *
     * @param  array  $source       Full source language strings (key => value)
     * @param  array  $existing     Existing target language strings (key => value)
     * @param  bool   $skipExisting Whether to skip strings that already differ from source
     * @return array  Strings that need translation (key => source_value)
     */
    public function getStringsToTranslate( array $source, array $existing, bool $skipExisting ): array
    {
        $toTranslate = [];

        foreach ( $source as $key => $value ) {
            if ( $skipExisting && isset( $existing[$key] ) && $existing[$key] !== $key && $existing[$key] !== $value ) {
                // Already translated (value differs from key and source value)
                continue;
            }

            $toTranslate[$key] = $value;
        }

        return $toTranslate;
    }

    /**
     * Translate a batch of strings using Ollama.
     *
     * @param  array  $strings       Associative array of key => source_value
     * @param  string $targetLang    Target language name (e.g. "French")
     * @param  string $targetCode    Target language code (e.g. "fr")
     * @return array  Translated strings as key => translated_value
     *
     * @throws \RuntimeException on API failure
     */
    public function translateBatch( array $strings, string $targetLang, string $targetCode ): array
    {
        if ( empty( $strings ) ) {
            return [];
        }

        $numberedStrings = [];
        $keys = array_keys( $strings );

        foreach ( array_values( $strings ) as $index => $value ) {
            $numberedStrings[] = ( $index + 1 ) . '. ' . $value;
        }

        $prompt = $this->buildPrompt( $numberedStrings, $targetLang, $targetCode );

        $response = Http::timeout( $this->getTimeout() )
            ->post( $this->getHost() . '/api/generate', [
                'model' => $this->getModel(),
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.1,
                    'num_predict' => 4096,
                ],
            ] );

        if ( ! $response->successful() ) {
            throw new \RuntimeException(
                'Ollama API request failed with status ' . $response->status() . ': ' . $response->body()
            );
        }

        $body = $response->json();
        $responseText = $body['response'] ?? '';

        return $this->parseTranslationResponse( $responseText, $keys );
    }

    /**
     * Build the translation prompt.
     */
    private function buildPrompt( array $numberedStrings, string $targetLang, string $targetCode ): string
    {
        $stringList = implode( "\n", $numberedStrings );

        return <<<PROMPT
You are a professional translator. Translate the following numbered strings from English to {$targetLang} ({$targetCode}).

Rules:
- Return ONLY the translated strings, one per line, with the same numbering.
- Preserve any placeholders like {name}, %s, %d, :attribute exactly as they are.
- Preserve HTML tags exactly as they are.
- Do not add explanations, notes, or any extra text.
- Keep the same numbering format: "1. translated text"
- If a string is a single word or technical term that doesn't need translation, keep it as is.

Strings to translate:
{$stringList}
PROMPT;
    }

    /**
     * Parse the numbered response from Ollama into an associative array.
     */
    private function parseTranslationResponse( string $response, array $keys ): array
    {
        $translations = [];
        $lines = array_filter( array_map( 'trim', explode( "\n", $response ) ) );

        foreach ( $lines as $line ) {
            // Match lines like "1. Translated text" or "1) Translated text"
            if ( preg_match( '/^\d+[\.\)]\s*(.+)$/', $line, $matches ) ) {
                $translations[] = $matches[1];
            }
        }

        $result = [];

        foreach ( $keys as $index => $key ) {
            if ( isset( $translations[$index] ) ) {
                $result[$key] = $translations[$index];
            }
        }

        return $result;
    }

    /**
     * Get all enabled modules that have a Lang directory.
     */
    public function getModulesWithLang(): array
    {
        $modules = [];
        $moduleService = app( \App\Services\ModulesService::class );
        $enabledModules = $moduleService->getEnabled();

        foreach ( $enabledModules as $module ) {
            $namespace = $module['namespace'] ?? null;

            if ( $namespace ) {
                $langPath = $this->getModuleLangPath( $namespace );

                if ( File::isDirectory( $langPath ) ) {
                    $modules[$namespace] = [
                        'namespace' => $namespace,
                        'name' => $module['name'] ?? $namespace,
                        'lang_path' => $langPath,
                    ];
                }
            }
        }

        return $modules;
    }

    /**
     * Test the Ollama connection.
     */
    public function testConnection(): array
    {
        try {
            $response = Http::timeout( 10 )
                ->get( $this->getHost() . '/api/tags' );

            if ( $response->successful() ) {
                $models = collect( $response->json( 'models', [] ) )
                    ->pluck( 'name' )
                    ->toArray();

                return [
                    'status' => 'success',
                    'message' => 'Connected to Ollama successfully.',
                    'models' => $models,
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Failed to connect: HTTP ' . $response->status(),
            ];
        } catch ( \Exception $e ) {
            return [
                'status' => 'error',
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }
}
