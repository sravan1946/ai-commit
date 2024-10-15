<?php

declare(strict_types=1);

/**
 * This file is part of the guanguans/ai-commit.
 *
 * (c) guanguans <ityaozm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace App\Generators;

use App\Contracts\GeneratorContract;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\HelperInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

abstract class Generator implements GeneratorContract
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Illuminate\Console\OutputStyle
     */
    protected $output;

    /**
     * @var \Symfony\Component\Console\Helper\HelperSet
     */
    protected $helperSet;

    /**
     * @var \Symfony\Component\Console\Helper\ProcessHelper
     */
    protected $processHelper;

    /**
     * @psalm-suppress UndefinedMethod
     * @noinspection PhpUndefinedMethodInspection
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->output = tap(clone resolve(OutputStyle::class))->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        $this->helperSet = (function () {
            return $this->getArtisan()->getHelperSet();
        })->call(Artisan::getFacadeRoot());
        $this->processHelper = $this->getHelper('process');
    }

    /**
     * @param array|string|\Symfony\Component\Process\Process $cmd
     * @noinspection MissingParameterTypeDeclarationInspection
     */
    public function processHelperMustRun(
        $cmd,
        ?string $error = null,
        ?callable $callback = null,
        int $verbosity = OutputInterface::VERBOSITY_VERY_VERBOSE,
        ?OutputInterface $output = null
    ): Process {
        $process = $this->processHelperRun($cmd, $error, $callback, $verbosity, $output);
        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    /**
     * @param array|string|\Symfony\Component\Process\Process $cmd
     * @noinspection MissingParameterTypeDeclarationInspection
     */
    public function processHelperRun(
        $cmd,
        ?string $error = null,
        ?callable $callback = null,
        int $verbosity = OutputInterface::VERBOSITY_VERY_VERBOSE,
        ?OutputInterface $output = null
    ): Process {
        if (\is_string($cmd)) {
            $cmd = Process::fromShellCommandline($cmd);
        }

        return $this->processHelper->run($output ?? $this->output, $cmd, $error, $callback, $verbosity);
    }

    public function defaultRunningCallback(): callable
    {
        $logger = $this->newConsoleLogger();

        return static function (string $type, string $data) use ($logger): void {
            // Process::OUT === $type ? $this->output->write($data) : $this->output->write("<fg=red>$data</>");
            Process::OUT === $type ? $logger->info($data) : $logger->error($data);
        };
    }

    public function newConsoleLogger(
        array $verbosityLevelMap = [],
        array $formatLevelMap = [],
        ?OutputInterface $output = null
    ): ConsoleLogger {
        return new ConsoleLogger($output ?? $this->output, $verbosityLevelMap, $formatLevelMap);
    }

    /**
     * @throws InvalidArgumentException if the helper is not defined
     */
    public function getHelper(string $name): HelperInterface
    {
        return $this->helperSet->get($name);
    }

    public function sanitizeJson(string $json): string
    {
        return (string) str($json)
            ->match('/\{.*\}/s')
            // ->replaceMatches('/[[:cntrl:]]/mu', '')
            ->replace(
                ["\\'", PHP_EOL],
                ["'", '']
            );
    }
}
