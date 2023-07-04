<?php

/**
 * @author Sebastian Pondo
 */


namespace Propel\Ext\Generator\Command;

use Propel\Ext\Generator\Command\Helper\PayloadContainer;
use Propel\Generator\Command\AbstractCommand;
use Propel\Generator\Config\GeneratorConfig;
use Propel\Generator\Manager\ModelManager;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;


class PayloadCommand extends AbstractCommand
{

    private string $payloadDir;
    private GeneratorConfig $generatorConfig;

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('payload-dir', null, InputOption::VALUE_REQUIRED, 'The payload files directory')
            ->addOption('connection', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Connection to use. Example: \'bookstore=mysql:host=127.0.0.1;dbname=test;user=root;password=foobar\' where "bookstore" is your propel database name (used in your schema.xml)')
            ->setName('payload:insert')
            ->setAliases(['insert-payload'])
            ->setDescription('Insert payload data');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configOptions = [];
        $this->generatorConfig = $this->getGeneratorConfig($configOptions, $input);

        $connections = [];
        $optionConnections = $input->getOption('connection');
        if (!$optionConnections) {
            $connections = $this->generatorConfig->getBuildConnections();
        } else {
            foreach ($optionConnections as $connection) {
                [$name, $dsn, $infos] = $this->parseConnection($connection);
                $connections[$name] = array_merge(['dsn' => $dsn], $infos);
            }
        }

        if ($input->getOption('payload-dir')) {
            $this->payloadDir = $input->getOption('payload-dir');
        } else {
            $this->payloadDir = $this->generatorConfig->getSection('paths')['projectDir'] . '/payload';
        }

        $finder = new Finder();

        foreach ($connections as $name => $config) {
            $dir = $this->payloadDir.'/'.$name;
            if (is_dir($dir)) {
                $dbName = $this->dbName($config['dsn'],'.db');
                $finder->files()->name('*.json')->in($dir)->depth(0);
                foreach ($finder as $file) {
                    $tmp = explode('_', $file->getBasename('.json'));
                    if (count($tmp) >= 2) {
                        unset($tmp[0]);
                        $table = implode('_',$tmp);
                        $insert = $this->payloadInsert($this->payloadJson($file->getPathName(), $table, $dbName));
                        $output->writeln("insert $insert records to $table in database $dbName");
                    }
                }
            }
        }

        return static::CODE_SUCCESS;
    }

    protected function payloadJson(string $path, string $table, string $dbName): PayloadContainer
    {
        $data = [];
        if (!file_exists($path)) {
            throw new \RuntimeException("payload $path not exists");
        }

        $queryPropel = $this->getQueryPropel($dbName, $table);
        $payload = file_get_contents($path);
        $rows = json_decode($payload, true);
        foreach ($rows as $row) {
            if (is_array($row)) {
                $values = [];
                foreach ($row as $key => $value) {
                    $values[$table . '.' . $key] = $value;
                }
                $data[] = $values;
            }
        }
        return new PayloadContainer($queryPropel, $data);
    }

    protected function payloadCsv(ModelCriteria $queryPropel): PayloadContainer
    {
        $data = [];
        $classPart = explode("\\",$queryPropel->getTableMap()->getClassName());
        $className = array_pop($classPart);
        $classPart[] = "Map";
        $classPart[] = $className."TableMap";
        $tableMap = implode("\\", $classPart);
        if (!method_exists($tableMap, 'translateFieldNames')) {
            throw new \RuntimeException("not exists translateFieldName");
        }
        $dbName = $queryPropel->getDbName();
        $table = $queryPropel->getTableMap()->getName();
        $path = $this->payloadDir.'/'.$dbName.'/db_'.$table.'.csv';
        if (!file_exists($path)) {
            throw new \RuntimeException("payload $path not exists");
        }
        $rows = array_map('str_getcsv', file($path));
        foreach ($rows as $row) {
            if (is_array($row)) {
                $data[] = $tableMap::translateFieldNames($row, TableMap::TYPE_NUM, TableMap::TYPE_COLNAME);
            }
        }
        return new PayloadContainer($queryPropel, $data);;
    }

    protected function payloadInsert(PayloadContainer $payload): int
    {
        $insert = 0;
        foreach ($payload->getData() as $row) {
            if (is_array($row)) {
                foreach ($row as $key => $val) {
                    $payload->getQueryPropel()->put($key, $val);
                }
                try {
                    $payload->getQueryPropel()->doInsert();
                    $insert++;
                } catch (PropelException $ex) {}
            }
        }
        return $insert;
    }

    protected function camelize($input): string
    {
        $separator = '_';
        return str_replace($separator, '', ucwords($input, $separator));
    }

    protected function dbName(string $dsn, string $suffix = ''): string
    {
        $separator = explode('/', $dsn);
        if (count($separator) >= 1) {
            return str_replace($suffix,'',$separator[count($separator)-1]);
        }
        return "";
    }

    protected function getQueryPropel($dbName, $table): ModelCriteria
    {
        $schema = $this->generatorConfig->getSection('paths')['schemaDir'].'/'.$dbName.'_schema.xml';

        if (!file_exists($schema)) {
            throw new \RuntimeException("schema $schema not exists");
        }

        $manager = new ModelManager();
        $manager->setFilesystem($this->getFilesystem());
        $manager->setGeneratorConfig($this->generatorConfig);
        $manager->setSchemas([new \SplFileInfo($schema)]);
        $database = $manager->getDatabases()[$dbName];

        $queryPropel = $database->getNamespace().'\\'.$this->camelize($table).'Query';
        if (!class_exists($queryPropel)) {
            throw new \RuntimeException("queryPropel $queryPropel not exists");
        }

        return $queryPropel::create();
    }

}
