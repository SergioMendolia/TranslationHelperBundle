<?php

namespace SergioMendolia\TranslationHelperBundle\Command;

use Symfony\Bundle\FrameworkBundle\DataCollector\RouterDataCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
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
        $io = new SymfonyStyle($input, $output);

        if (!$this->profiler instanceof Profiler) {
            $io->error('Profiler not available');
            return Command::FAILURE;
        }
        $dryRun = $input->hasOption('dry-run');
        $fail = $input->hasOption('fail');
        $limit = null;
        if($input->hasOption('limit')) {
            $limit = $input->getOption('limit');
        }
        $profiles = $this->profiler->find(null, null,$limit, '', '', '', '200');

        $contents = [];
        $table_contents = [];
        foreach ($profiles as $profile) {
            $io->writeln('Processing: '.$profile['url']);

            $parsedUrl = parse_url($profile['url']);

            $route = $profile['url'];
            if (array_key_exists('path', $parsedUrl)) {
                $route = $this->router->match($parsedUrl['path']);
                $route = $route['_route'];
            }

            $prof = $this->profiler->loadProfile($profile['token']);

            if (!$prof instanceof Profile || !$prof->hasCollector('translation')) {
                $io->warning('No translation collector found for this profile');
                continue;
            }

            /** @var TranslationDataCollector $collector */
            $collector = $prof->getCollector('translation');

            /** @var Data $collector_messages */
            $collector_messages = $collector->getMessages();
            $raw_values = $collector_messages->getValue(true);

            if (!is_array($raw_values) || $raw_values === []) {
                $io->success('No messages found');
                continue;
            }

            $filtered_values = array_filter($raw_values, static fn ($value) => $value['state'] !== 0);

            $grouped = self::groupBy($filtered_values, 'domain');

            foreach ($grouped as $domain => $values) {
                $byLocale = self::groupBy($values, 'locale');

                foreach (array_keys($byLocale) as $locale) {
                    $filename = $domain;
                    if ($domain === 'messages') {
                        $filename .= MessageCatalogueInterface::INTL_DOMAIN_SUFFIX;
                    }
                    $filename .= '.'.$locale.'.yaml';
                    $filename = $this->projectDir.'/translations/'.$filename;

                    if(!file_exists($filename)) {
                        $io->warning('File not found: '.$filename);
                        continue;
                    }

                    $yaml = Yaml::parseFile($filename);
                    if (!is_array($yaml)) {
                        throw new \RuntimeException('Invalid yaml file');
                    }

                    $io->title('Translations found: '.$filename);

                    foreach ($values as $message) {
                        if (array_key_exists($message['id'], $yaml)) {
                            continue;
                        }
                        $line = ''.$message['id'].': "__'.$message['translation'].'"';
                        $contents[$message['id']] = $line;
                        $table_contents[$message['id']] = [$message['id'], '__'.$message['translation'],$route];
                    }


                }
            }

        }
        $io->table(['Message', 'Translation','url'], $table_contents);

        if(count($contents) === 0) {
            $io->success('No missing translations found');
            return Command::SUCCESS;
        }

        if($fail) {
            $io->error('Missing translations found');
            return Command::FAILURE;
        }

        if(!$dryRun) {
            file_put_contents($filename, implode("\n", $contents) . "\n", FILE_APPEND);
        }
        $io->writeln('');
        return Command::SUCCESS;
    }

    protected static function groupBy(array $array, string $columnName): array
    {
        $newArray = [];
        foreach ($array as $value) {
            $index = $value[$columnName];
            $newArray[$index][] = $value;
        }

        return $newArray;
    }
}
