<?php

declare(strict_types=1);

/**
 * This file is part of the guanguans/ai-commit.
 *
 * (c) guanguans <ityaozm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace App\Commands;

use App\ConfigManager;
use App\Exceptions\RuntimeException;
use App\Exceptions\UnsupportedActionOfConfigException;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ConfigCommand extends Command
{
    /**
     * @var string[]
     */
    public const ACTIONS = ['set', 'get', 'unset', 'list', 'edit'];

    /**
     * @var string
     */
    protected $signature = 'config';

    /**
     * @var string
     */
    protected $description = 'Manage config options.';

    /**
     * @var \App\ConfigManager
     */
    protected $configManager;

    public function __construct()
    {
        $this->configManager = config('ai-commit');
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDefinition([
            new InputArgument('action', InputArgument::REQUIRED, sprintf('The action(<comment>[%s]</comment>) name', implode(', ', self::ACTIONS))),
            new InputArgument('key', InputArgument::OPTIONAL, 'The key of config options'),
            new InputArgument('value', InputArgument::OPTIONAL, 'The value of config options'),
            new InputOption('global', 'g', InputOption::VALUE_NONE, 'Apply to the global config file'),
            new InputOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Apply to the specify config file'),
            new InputOption('editor', 'e', InputOption::VALUE_OPTIONAL, 'Specify editor'),
        ]);
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (! file_exists($this->configManager::globalPath())) {
            $this->configManager->toGlobal();
        }
    }

    public function handle(): int
    {
        $file = value(function () {
            if ($file = $this->option('file')) {
                return $file;
            }

            if ($this->option('global')) {
                return ConfigManager::globalPath();
            }

            return ConfigManager::localPath();
        });

        $this->output->info("The config file($file) is being operated.");
        file_exists($file) or $this->configManager->toFile($file);
        $this->configManager->replaceFrom($file);
        $action = $this->argument('action');
        $key = $this->argument('key');

        if (in_array($action, ['unset', 'set'], true) && null === $key) {
            $this->output->error('Please specify the parameter key.');

            return self::FAILURE;
        }

        switch ($action) {
            case 'set':
                $this->configManager->set($key, $this->argument('value'));
                $this->configManager->toFile($file);

                break;
            case 'get':
                $value = null === $key ? $this->configManager->toJson() : $this->configManager->get($key);
                $this->line($this->transformToCommandStr($value));

                break;
            case 'unset':
                $this->configManager->forget($key);
                $this->configManager->toFile($file);

                break;
            case 'list':
                /**
                 * @param array-key|null $prefixKey
                 */
                $flattenWithKeys = static function (array $array, string $delimiter = '.', $prefixKey = null) use (&$flattenWithKeys): array {
                    $result = [];
                    foreach ($array as $key => $value) {
                        $fullKey = null === $prefixKey ? $key : $prefixKey.$delimiter.$key;
                        is_array($value) ? $result += $flattenWithKeys($value, $delimiter, $fullKey) : $result[$fullKey] = $value;
                    }

                    return $result;
                };

                collect($flattenWithKeys($this->configManager->all()))
                    ->each(function ($value, $key) {
                        $this->line(sprintf(
                            '<comment>[%s]</comment> <info>%s</info>',
                            $this->transformToCommandStr($key),
                            $this->transformToCommandStr($value)
                        ));
                    });

                break;
            case 'edit':
                $editor = value(function () {
                    if ($editor = $this->option('editor')) {
                        return $editor;
                    }

                    if (windows_os()) {
                        return 'notepad';
                    }

                    foreach (['editor', 'vim', 'vi', 'nano', 'pico', 'ed'] as $editor) {
                        if (exec("which $editor")) {
                            return $editor;
                        }
                    }

                    throw new RuntimeException('No editor found or specified.');
                });

                Process::fromShellCommandline("$editor $file")->setTimeout(null)->setTty(true)->mustRun();

                break;
            default:
                throw UnsupportedActionOfConfigException::make($action);
        }

        $this->output->success('Operate successfully.');

        return self::SUCCESS;
    }

    /**
     * @param mixed $value
     *
     * @psalm-suppress NullableReturnStatement
     */
    protected function transformToCommandStr($value): string
    {
        return transform(
            $value,
            $transform = static function ($value): string {
                true === $value and $value = 'true';
                false === $value and $value = 'false';
                0 === $value and $value = '0';
                0.0 === $value and $value = '0.0';
                null === $value and $value = 'null';
                ! is_scalar($value) and $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                return (string) $value;
            },
            $transform
        );
    }

    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
