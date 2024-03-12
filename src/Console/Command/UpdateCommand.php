<?php

declare(strict_types=1);

namespace Composer\Satis\Console\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Dotenv\Dotenv;

class UpdateCommand extends BaseCommand
{
    protected function configure(): void
    {
            $this->getName() ?? $this->setName('update');
        $this
            ->setDescription('Fetches GitHub repositories from environment variables and updates satis.json')
            ->setHelp(
                <<<'EOT'
                The <info>update</info> command fetches the list of repositories from the GitHub 
                organization specified in the environment variables, and updates the satis.json file 
                with these repositories. Each repository is added with its clone URL and the GitHub 
                token for authorization. After running this command, you will need to execute 
                the <comment>build</comment> command to fetch updates from these repositories and 
                build the Satis repository
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dotenv = new Dotenv();
        $dotenv->load(dirname(__DIR__, 3) . '/.env');

        $command = [
            'curl',
            '-L',
            '-H',
            'Accept: application/vnd.github+json',
            '-H',
            "Authorization: Bearer {$_ENV['GITHUB_TOKEN']}",
            '-H',
            'X-GitHub-Api-Version: 2022-11-28',
            "https://api.github.com/orgs/{$_ENV['GITHUB_ORGANIZATION']}/repos"
        ];

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $satisConfig = [
            'name'         => $_ENV['GITHUB_REPOSITORY_NAME'],
            'homepage'     => $_ENV['GITHUB_ORGANIZATION_HOMEPAGE'],
            'repositories' => [],
            'require-all'  => (bool) $_ENV['SATIS_CONFIG_REQUIRE_ALL'] ?? false,
            'output-html'  => (bool) $_ENV['SATIS_CONFIG_OUTPUT_HTML'] ?? false,
            'archive'      => [
                'directory' => $_ENV['SATIS_CONFIG_ARCHIVE_DIRECTORY'] ?? 'dist',
                'format'    => $_ENV['SATIS_CONFIG_ARCHIVE_FORMAT'] ?? 'zip',
                'skip-dev'  => (bool) $_ENV['SATIS_CONFIG_ARCHIVE_SKIP_DEV'] ?? true,
            ],
        ];

        $commandOutput = $process->getOutput();
        foreach (json_decode($commandOutput) as $repo) {
            $satisConfig['repositories'][] =
                [
                    'type'    => 'vcs',
                    'url'     => $repo->clone_url,
                    'options' => [
                        'http' => [
                            'header' => ["API-TOKEN: {$_ENV['GITHUB_TOKEN']}"]
                        ],
                    ],
                ];
        }

        $satisJson = new JsonFile(dirname(__DIR__, 3) . "/{$_ENV['SATIS_CONFIG_FILE']}");
        try {
            $satisJson->write($satisConfig);
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
        $output->writeln('<info>Satis configuration updated</info>');
        return 0;
    }
}
