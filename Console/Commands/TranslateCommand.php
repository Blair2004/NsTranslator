<?php

namespace Modules\NsTranslator\Console\Commands;

use Illuminate\Console\Command;
use Modules\NsTranslator\Services\TranslationService;

class TranslateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ns-translator:translate
        {--lang=* : Target language code(s). If empty, all target languages are translated.}
        {--scope=all : Scope of translation: core, modules, or all}
        {--module= : Translate a specific module by namespace}
        {--test-connection : Test the Ollama connection and exit}
        {--dry-run : Show what would be translated without actually translating}';

    /**
     * The console command description.
     */
    protected $description = 'Translate NexoPOS core and module localization files using Ollama AI.';

    public function __construct(
        private TranslationService $translationService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ( $this->option( 'test-connection' ) ) {
            return $this->handleTestConnection();
        }

        $scope = $this->option( 'scope' );
        $targetLangs = $this->option( 'lang' );
        $specificModule = $this->option( 'module' );
        $dryRun = $this->option( 'dry-run' );

        // Resolve target languages
        $availableTargets = $this->translationService->getTargetLanguages();

        if ( ! empty( $targetLangs ) ) {
            $filtered = [];

            foreach ( $targetLangs as $code ) {
                if ( isset( $availableTargets[$code] ) ) {
                    $filtered[$code] = $availableTargets[$code];
                } else {
                    $this->warn( "Language '{$code}' is not configured. Skipping." );
                }
            }

            $availableTargets = $filtered;
        }

        if ( empty( $availableTargets ) ) {
            $this->error( 'No valid target languages found.' );

            return 1;
        }

        $sourceLang = $this->translationService->getSourceLanguage();

        $this->info( '╔══════════════════════════════════════════════╗' );
        $this->info( '║       NexoPOS Translator (Ollama AI)        ║' );
        $this->info( '╚══════════════════════════════════════════════╝' );
        $this->newLine();
        $this->line( '  Source language : <comment>' . $this->translationService->getLanguageName( $sourceLang ) . " ({$sourceLang})</comment>" );
        $this->line( '  Target(s)      : <comment>' . implode( ', ', array_keys( $availableTargets ) ) . '</comment>' );
        $this->line( '  Scope           : <comment>' . $scope . '</comment>' );
        $this->line( '  Model           : <comment>' . $this->translationService->getModel() . '</comment>' );
        $this->line( '  Batch size      : <comment>' . $this->translationService->getBatchSize() . '</comment>' );
        $this->line( '  Skip existing   : <comment>' . ( $this->translationService->shouldSkipExisting() ? 'Yes' : 'No' ) . '</comment>' );

        if ( $dryRun ) {
            $this->line( '  Mode            : <fg=yellow;options=bold>DRY RUN</>' );
        }

        $this->newLine();

        $totalTranslated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        // Translate core
        if ( in_array( $scope, ['core', 'all'] ) && ! $specificModule ) {
            $result = $this->translateSource(
                label: 'NexoPOS Core',
                langPath: $this->translationService->getCoreLangPath(),
                targetLanguages: $availableTargets,
                sourceLang: $sourceLang,
                dryRun: $dryRun,
            );

            $totalTranslated += $result['translated'];
            $totalSkipped += $result['skipped'];
            $totalErrors += $result['errors'];
        }

        // Translate modules
        if ( in_array( $scope, ['modules', 'all'] ) ) {
            $modules = $this->translationService->getModulesWithLang();

            if ( $specificModule ) {
                if ( isset( $modules[$specificModule] ) ) {
                    $modules = [$specificModule => $modules[$specificModule]];
                } else {
                    $this->error( "Module '{$specificModule}' not found or has no Lang directory." );

                    return 1;
                }
            }

            foreach ( $modules as $module ) {
                $result = $this->translateSource(
                    label: "Module: {$module['name']}",
                    langPath: $module['lang_path'],
                    targetLanguages: $availableTargets,
                    sourceLang: $sourceLang,
                    dryRun: $dryRun,
                );

                $totalTranslated += $result['translated'];
                $totalSkipped += $result['skipped'];
                $totalErrors += $result['errors'];
            }
        }

        $this->newLine();
        $this->info( '══════════════════════════════════════════════' );
        $this->info( '  Summary' );
        $this->info( '══════════════════════════════════════════════' );
        $this->line( "  Translated : <info>{$totalTranslated}</info> strings" );
        $this->line( "  Skipped    : <comment>{$totalSkipped}</comment> strings" );

        if ( $totalErrors > 0 ) {
            $this->line( "  Errors     : <error>{$totalErrors}</error> batches" );
        }

        $this->newLine();

        if ( $dryRun ) {
            $this->comment( '  This was a dry run. No files were modified.' );
            $this->newLine();
        }

        return $totalErrors > 0 ? 1 : 0;
    }

    /**
     * Translate all target languages for a given source (core or module).
     */
    private function translateSource(
        string $label,
        string $langPath,
        array $targetLanguages,
        string $sourceLang,
        bool $dryRun,
    ): array {
        $this->newLine();
        $this->info( "  ── {$label} " . str_repeat( '─', max( 1, 40 - strlen( $label ) ) ) );

        $sourceFile = $langPath . '/' . $sourceLang . '.json';
        $sourceStrings = $this->translationService->loadTranslations( $sourceFile );

        if ( empty( $sourceStrings ) ) {
            $this->warn( "    No source file found at {$sourceFile}. Skipping." );

            return ['translated' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $this->line( "    Source strings: <comment>" . count( $sourceStrings ) . '</comment>' );

        $totalTranslated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $skipExisting = $this->translationService->shouldSkipExisting();

        foreach ( $targetLanguages as $code => $name ) {
            $targetFile = $langPath . '/' . $code . '.json';
            $existingTranslations = $this->translationService->loadTranslations( $targetFile );

            $toTranslate = $this->translationService->getStringsToTranslate(
                $sourceStrings,
                $existingTranslations,
                $skipExisting
            );

            $skippedCount = count( $sourceStrings ) - count( $toTranslate );
            $totalSkipped += $skippedCount;

            $this->newLine();
            $this->line( "    <fg=cyan>► {$name} ({$code})</>" );
            $this->line( "      To translate: <comment>" . count( $toTranslate ) . '</comment> | Skipped: <comment>' . $skippedCount . '</comment>' );

            if ( empty( $toTranslate ) ) {
                $this->line( '      <info>✓ All strings already translated.</info>' );

                continue;
            }

            if ( $dryRun ) {
                $this->line( '      <comment>⊘ Dry run — would translate ' . count( $toTranslate ) . ' strings.</comment>' );
                $totalTranslated += count( $toTranslate );

                continue;
            }

            // Process in batches
            $batches = array_chunk( $toTranslate, $this->translationService->getBatchSize(), true );
            $batchCount = count( $batches );

            $bar = $this->output->createProgressBar( $batchCount );
            $bar->setFormat( '      %current%/%max% [%bar%] %percent:3s%% %message%' );
            $bar->setMessage( 'Translating...' );
            $bar->start();

            $translatedInLang = 0;

            foreach ( $batches as $batchIndex => $batch ) {
                try {
                    $translated = $this->translationService->translateBatch( $batch, $name, $code );

                    // Merge translations
                    $existingTranslations = array_merge( $existingTranslations, $translated );
                    $translatedInLang += count( $translated );

                    $bar->setMessage( $translatedInLang . ' strings done' );
                } catch ( \Exception $e ) {
                    $totalErrors++;
                    $bar->setMessage( '<error>Batch error</error>' );
                    $this->newLine();
                    $this->error( "      Batch " . ( $batchIndex + 1 ) . " failed: {$e->getMessage()}" );
                }

                $bar->advance();
            }

            $bar->setMessage( '<info>✓ ' . $translatedInLang . ' strings translated</info>' );
            $bar->finish();
            $this->newLine();

            // Save the merged translations
            if ( $translatedInLang > 0 ) {
                // Sort keys alphabetically for consistency
                ksort( $existingTranslations );
                $this->translationService->saveTranslations( $targetFile, $existingTranslations );
                $this->line( "      Saved to <comment>{$targetFile}</comment>" );
            }

            $totalTranslated += $translatedInLang;
        }

        return [
            'translated' => $totalTranslated,
            'skipped' => $totalSkipped,
            'errors' => $totalErrors,
        ];
    }

    /**
     * Test the Ollama connection.
     */
    private function handleTestConnection(): int
    {
        $this->info( 'Testing Ollama connection...' );
        $this->line( '  Host  : ' . $this->translationService->getHost() );
        $this->line( '  Model : ' . $this->translationService->getModel() );
        $this->newLine();

        $result = $this->translationService->testConnection();

        if ( $result['status'] === 'success' ) {
            $this->info( '  ✓ ' . $result['message'] );
            $this->newLine();
            $this->line( '  Available models:' );

            foreach ( $result['models'] ?? [] as $model ) {
                $configuredModel = $this->translationService->getModel();
                $marker = str_starts_with( $configuredModel, explode( ':', $model )[0] ) ? ' <info>← configured</info>' : '';
                $this->line( "    • {$model}{$marker}" );
            }

            $this->newLine();

            return 0;
        }

        $this->error( '  ✗ ' . $result['message'] );
        $this->newLine();

        return 1;
    }
}
