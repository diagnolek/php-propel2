<?php

/**
 * @author Sebastian Pondo
 */

namespace Propel\Ext\Generator\Command;

use Propel\Generator\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbClearCommand extends AbstractCommand
{
    private string $dataDir;

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('data-dir', null, InputOption::VALUE_REQUIRED, 'The database files directory')
            ->addOption('connection', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Connection to use. Example: \'bookstore=mysql:host=127.0.0.1;dbname=test;user=root;password=foobar\' where "bookstore" is your propel database name (used in your schema.xml)')
            ->setName('payload:remove')
            ->setAliases(['remove-payload'])
            ->setDescription('Delete payload data');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configOptions = [];
        $generatorConfig = $this->getGeneratorConfig($configOptions, $input);

        $connections = [];
        $optionConnections = $input->getOption('connection');
        if (!$optionConnections) {
            $connections = $generatorConfig->getBuildConnections();
        } else {
            foreach ($optionConnections as $connection) {
                [$name, $dsn, $infos] = $this->parseConnection($connection);
                $connections[$name] = array_merge(['dsn' => $dsn], $infos);
            }
        }

        $projectDir = $generatorConfig->getSection('paths')['projectDir'];

        if ($input->getOption('data-dir')) {
            $this->dataDir = $input->getOption('data-dir');
        } else {
            $this->dataDir =  $projectDir;
        }

        foreach ($connections as $config) {
            if (isset($config['dsn'])) {
                $dsn = explode(":", $config['dsn']);
                $file = $this->dataDir.'/'.$this->dbName($config['dsn'],'');
                if (count($dsn) >= 1 && $dsn[0] == 'sqlite' && file_exists($file)) {
                    unlink($file);
                    $output->writeln("clear ".$file." remove all tables");
                }
            }
        }

        $command = 'php '.$_SERVER['argv'][0].' sql:insert --config-dir='.$projectDir;
        if ($optionConnections) {
            $command .= ' --connection='.$optionConnections;
        }
        exec($command);

        return static::CODE_SUCCESS;
    }

    protected function dbName(string $dsn, string $suffix = ''): string
    {
        $separator = explode('/', $dsn);
        if (count($separator) >= 1) {
            return str_replace($suffix,'',$separator[count($separator)-1]);
        }
        return "";
    }
}
