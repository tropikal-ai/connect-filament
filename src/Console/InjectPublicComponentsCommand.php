<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Console;

use Illuminate\Console\Command;
use TropikalAI\Connect\Infrastructure\PublicChannels\PublicAssets;
use TropikalAI\ConnectFilament\Http\Middleware\InjectPublicComponents;

final class InjectPublicComponentsCommand extends Command
{
    protected $signature = 'tropikal-connect:inject-public-components {--path=* : HTML file or directory to update}';

    protected $description = 'Install the stable TROPIKAL public-components bootstrap into static HTML.';

    public function handle(): int
    {
        $paths = $this->option('path');
        $paths = is_array($paths) && $paths !== []
            ? $paths
            : config('connect-filament.public_components.static_entry_points', []);
        $files = $this->resolveFiles(is_array($paths) ? $paths : []);
        if ($files === []) {
            $this->error('No HTML entry points were found.');

            return self::FAILURE;
        }
        try {
            foreach ($files as $file) {
                $this->inject($file);
                $this->line($file);
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<int, mixed> $paths @return list<string> */
    private function resolveFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            $path = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
                foreach ($iterator as $file) {
                    if ($file->isFile() && strtolower($file->getExtension()) === 'html') {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function inject(string $file): void
    {
        $html = file_get_contents($file);
        if (! is_string($html)) {
            throw new \RuntimeException("Unable to read {$file}.");
        }
        $count = substr_count($html, InjectPublicComponents::MARKER);
        if ($count > 1) {
            throw new \RuntimeException("{$file} contains duplicate TROPIKAL bootstraps.");
        }
        $script = sprintf(
            '<script defer %s src="/tropikal-connect/assets/public-channels.js?v=%s"></script>',
            InjectPublicComponents::MARKER,
            PublicAssets::version(),
        );
        if ($count === 1) {
            $updated = preg_replace(
                '/<script\b[^>]*\b'.InjectPublicComponents::MARKER.'\b[^>]*>\s*<\/script>/i',
                $script,
                $html,
                1,
                $replacements,
            );
            if (! is_string($updated) || $replacements !== 1) {
                throw new \RuntimeException("{$file} has an invalid TROPIKAL bootstrap marker.");
            }
        } else {
            $position = strripos($html, '</body>');
            if ($position === false) {
                throw new \RuntimeException("{$file} has no closing body tag.");
            }
            $updated = substr($html, 0, $position).$script.substr($html, $position);
        }
        if ($updated === $html) {
            return;
        }
        if (file_put_contents($file, $updated, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to update {$file}.");
        }
    }
}
