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

use App\Exceptions\TaskException;
use App\GeneratorManager;
use App\Support\JsonFixer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

class CommitCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'commit';

    /**
     * @var string
     */
    protected $description = 'Automagically generate conventional commit messages with AI.';

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
     * The configuration of the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setDefinition([
            new InputArgument('path', InputArgument::OPTIONAL, 'The working directory', $this->configManager::localPath('')),
            new InputOption('commit-options', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Append options for the `git commit` command', $this->configManager->get('commit_options')),
            new InputOption('diff-options', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Append options for the `git diff` command', $this->configManager->get('diff_options')),
            new InputOption('generator', 'g', InputOption::VALUE_REQUIRED, 'Specify generator name', $this->configManager->get('generator')),
            new InputOption('num', null, InputOption::VALUE_REQUIRED, 'Specify number of generated messages', $this->configManager->get('num')),
            new InputOption('prompt', 'p', InputOption::VALUE_REQUIRED, 'Specify prompt name of messages generated', $this->configManager->get('prompt')),
            new InputOption('no-edit', null, InputOption::VALUE_NONE, 'Force no edit mode'),
        ]);
    }

    public function handle()
    {
        $this->task('   Checking run environment', function () use (&$stagedDiff) {
            $isInsideWorkTree = $this->createProcess('git rev-parse --is-inside-work-tree')
                ->mustRun()
                ->getOutput();
            if (! \str($isInsideWorkTree)->rtrim()->is('true')) {
                $message = <<<'message'
It looks like you are not in a git repository.
Please run this command from the root of a git repository, or initialize one using `git init`.
message;

                throw new TaskException($message);
            }

            $stagedDiff = $this->createProcess($this->getDiffCommand())->mustRun()->getOutput();
            if (empty($stagedDiff)) {
                throw new TaskException('There are no staged files to commit. Try running `git add` to stage some files.');
            }
        }, 'checking...');

        $this->task('   Generating commit messages', function () use (&$messages, $stagedDiff) {
            $generator = $this->laravel->get(GeneratorManager::class)->driver($this->option('generator'));
            $messages = $generator->generate($this->getPromptOfAI($stagedDiff));
            if (\str($messages)->isEmpty()) {
                throw new TaskException('No commit messages generated.');
            }

            $messages = $this->tryFixMessages($messages);
            if (! \str($messages)->isJson()) {
                throw new TaskException('The generated commit messages is an invalid JSON.');
            }

            $this->line('');
            $this->line('');
        }, 'generating...');

        $this->task('   Choosing commit message', function () use ($messages, &$message) {
            $messages = collect(json_decode($messages, true));
            $chosenSubject = $this->choice('Please choice a commit message', $messages->pluck('subject', 'id')->all());
            $message = $messages->first(function ($message) use ($chosenSubject) {
                return $message['subject'] === $chosenSubject;
            });
        }, 'choosing...');

        $this->task('   Committing message', function () use ($message) {
            $this->createProcess($this->getCommitCommand($message))
                ->setTty(true)
                ->setTimeout(null)
                ->mustRun();
        }, 'committing...');

        return self::SUCCESS;
    }

    /**
     * @param string|array $command
     */
    protected function createProcess($command, string $cwd = null, array $env = null, $input = null, ?float $timeout = 60): Process
    {
        /** @noinspection CallableParameterUseCaseInTypeContextInspection */
        null === $cwd and $cwd = $this->argument('path');
        if (is_string($command)) {
            return Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        }

        return new Process($command, $cwd, $env, $input, $timeout);
    }

    protected function getDiffCommand(): array
    {
        return array_merge(['git', 'diff', '--staged'], $this->option('diff-options'));
    }

    protected function getPromptOfAI(string $stagedDiff): string
    {
        return (string) \str($this->configManager->get("prompts.{$this->option('prompt')}"))
            ->replace(
                [$this->configManager->get('diff_mark'), $this->configManager->get('num_mark')],
                [$stagedDiff, $this->option('num')]
            )
            ->when($this->option('verbose'), function (Stringable $diff) {
                $this->line('');
                $this->comment('============================ start prompt ============================');

                $diff->explode(PHP_EOL)->each(function (string $line) {
                    if (\str($line)->startsWith('+')) {
                        $this->info($line);

                        return;
                    }

                    if (\str($line)->startsWith('-')) {
                        $this->error($line);

                        return;
                    }

                    if (\str($line)->startsWith('@@')) {
                        $this->comment($line);

                        return;
                    }

                    $this->line($line);
                });

                $this->comment('============================= end prompt =============================');
            });
    }

    protected function tryFixMessages(string $messages): string
    {
        return (string) (new JsonFixer())
            ->missingValue('')
            ->silent()
            ->fix(substr($messages, strpos($messages, '[')));
    }

    protected function getCommitCommand(array $message): array
    {
        return collect($message)
            ->filter(function ($val) {
                return $val && is_string($val);
            })
            ->map(function (string $val) {
                return trim($val, " \t\n\r\x0B");
            })
            ->pipe(function (Collection $message): array {
                $options = collect($this->option('commit-options'))
                    ->push('--edit')
                    ->pipe(function (Collection $options): Collection {
                        $noEdit = $this->option('no-edit') ?: ! $this->configManager->get('edit');
                        if ($noEdit) {
                            return $options->filter(function (string $option): bool {
                                return '--edit' !== $option;
                            });
                        }

                        return $options;
                    })
                    ->all();

                return array_merge(['git', 'commit', '--message', $message->implode(str_repeat(PHP_EOL, 2))], $options);
            });
    }

    /**
     * Define the command's schedule.
     *
     * @return void
     */
    public function schedule(Schedule $schedule)
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
