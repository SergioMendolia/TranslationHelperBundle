<?php

namespace SergioMendolia\TranslationHelperBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\DataCollector\TranslationDataCollector;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'translation:retrieve-from-profiler',
    description: 'Retrieve missing translations from symfony profiler',
)]
class TranslationRetrieveFromProfilerCommand extends Command
{
    public function __construct(private readonly RouterInterface $router, private readonly ?Profiler $profiler, #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many profiles to retrieve', 20)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print the missing messages in console')
            ->addOption('fail', null, InputOption::VALUE_NONE, 'Return a failure if there are missing translations')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        if (!$this->profiler instanceof Profiler) {
            $symfonyStyle->error('Profiler not available');

            return Command::FAILURE;
        }
        $dryRun = $input->hasOption('dry-run');
        $fail = $input->hasOption('fail');
        $limit = null;
        if ($input->hasOption('limit')) {
            $limit = $input->getOption('limit');
            if (!is_int($limit)) {
                $symfonyStyle->error('Limit must be a number');
                $limit = null;
            }
        }
        $profiles = $this->profiler->find(null, null, $limit, '', '', '', '200');

        $contents = [];
        $table_contents = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                $symfonyStyle->error('Invalid profile');
                continue;
            }
            $url = !array_key_exists('url', $profile)
            || !is_string($profile['url']) ? 'unknown' : $profile['url'];
            $symfonyStyle->writeln('Processing: '.$url);

            $route = $url;
            $profileUrl = $profile['url'];
            if ($url !== 'unknown' && is_string($profileUrl)) {
                $parsedUrl = parse_url($profileUrl);

                if (!is_array($parsedUrl)) {
                    $symfonyStyle->warning('Invalid url');
                    $parsedUrl = [
                        'path' => $url,
                    ];
                }

                if (array_key_exists('path', $parsedUrl)) {
                    $route = $this->router->match($parsedUrl['path']);
                    $route = $route['_route'];
                }
            }

            if (!array_key_exists('token', $profile) || !is_string($profile['token'])) {
                $symfonyStyle->warning('No token found for this profile');
                continue;
            }

            $prof = $this->profiler->loadProfile($profile['token']);

            if (!$prof instanceof Profile || !$prof->hasCollector('translation')) {
                $symfonyStyle->warning('No translation collector found for this profile');
                continue;
            }

            /** @var TranslationDataCollector $collector */
            $collector = $prof->getCollector('translation');

            /** @var Data $collector_messages */
            $collector_messages = $collector->getMessages();
            $raw_values = $collector_messages->getValue(true);

            if (!is_array($raw_values) || $raw_values === []) {
                $symfonyStyle->success('No messages found');
                continue;
            }

            $filtered_values = array_filter($raw_values, static fn ($value) => $value['state'] !== 0);

            $grouped = self::groupBy($filtered_values, 'domain');

            foreach ($grouped as $domain => $values) {
                if (!is_array($values)) {
                    continue;
                }
                $byLocale = self::groupBy($values, 'locale');

                foreach (array_keys($byLocale) as $locale) {
                    $filename = $domain;
                    if ($domain === 'messages') {
                        $filename .= MessageCatalogueInterface::INTL_DOMAIN_SUFFIX;
                    }
                    $filename .= '.'.$locale.'.yaml';
                    $filename = $this->projectDir.'/translations/'.$filename;

                    if (!file_exists($filename)) {
                        $symfonyStyle->warning('File not found: '.$filename);
                        continue;
                    }

                    $yaml = Yaml::parseFile($filename);
                    if (!is_array($yaml)) {
                        throw new \RuntimeException('Invalid yaml file');
                    }

                    $symfonyStyle->title('Translations found: '.$filename);

                    foreach ($values as $value) {
                        /** @var array{'id': int, "translation": string} $value */
                        if (array_key_exists($value['id'], $yaml)) {
                            continue;
                        }
                        $line = ''.$value['id'].': "__'.$value['translation'].'"';
                        if (!array_key_exists($filename, $contents)) {
                            $contents[$filename] = [];
                        }
                        if (!array_key_exists($filename, $table_contents)) {
                            $table_contents[$filename] = [];
                        }

                        $contents[$filename][$value['id']] = $line;
                        $table_contents[$filename][$value['id']] = [$value['id'], '__'.$value['translation'], $route];
                    }
                }
            }
        }
        foreach ($table_contents as $filename => $table_content) {
            $symfonyStyle->title('Translations file: '.$filename);
            $symfonyStyle->table(['Message', 'Translation', 'url'], $table_content);
        }

        if ($contents === []) {
            $symfonyStyle->success('No missing translations found');

            return Command::SUCCESS;
        }

        if ($fail) {
            $symfonyStyle->error('Missing translations found');

            return Command::FAILURE;
        }

        if (!$dryRun) {
            foreach ($contents as $filename => $content) {
                file_put_contents($filename, implode("\n", $content)."\n", FILE_APPEND);
            }
        }
        $symfonyStyle->writeln('');

        return Command::SUCCESS;
    }

    /**
     * @param array<mixed, mixed> $array
     *
     * @return array<mixed, mixed>
     */
    protected static function groupBy(array $array, string $columnName): array
    {
        $newArray = [];
        foreach ($array as $value) {
            if (!is_array($value) || !array_key_exists($columnName, $value)) {
                continue;
            }
            $index = $value[$columnName];
            $newArray[$index][] = $value;
        }

        return $newArray;
    }
}
