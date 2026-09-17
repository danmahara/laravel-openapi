<?php

namespace Danmahara\LaravelOpenApi\Console;

use Danmahara\LaravelOpenApi\Generator\OpenApiGenerator;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Symfony\Component\Yaml\Yaml;

class GenerateOpenApiCommand extends Command
{
    protected $signature = 'openapi:generate {--format=json : Output format: json or yaml}';

    protected $description = 'Generate an OpenAPI 3.1 specification from route attributes.';

    public function handle(Router $router): int
    {
        $config = config('openapi', []);

        $generator = new OpenApiGenerator($router, $config);
        $spec = $generator->generate();

        $format = $this->option('format');
        $outputPath = $config['output_path'] ?? storage_path('app/openapi.json');

        if ($format === 'yaml') {
            $outputPath = preg_replace('/\.json$/', '.yaml', $outputPath);
            file_put_contents($outputPath, Yaml::dump($spec, 10, 2));
        } else {
            file_put_contents($outputPath, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $pathCount = count($spec['paths']);
        $this->info("OpenAPI spec written to {$outputPath} ({$pathCount} paths documented).");

        return self::SUCCESS;
    }
}
