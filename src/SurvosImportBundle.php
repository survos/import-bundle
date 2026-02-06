<?php
/** generated from /home/tac/g/sites/survos/vendor/survos/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\ImportBundle;

use Survos\ImportBundle\Command\ImportBrowseCommand;
use Survos\ImportBundle\Command\ImportConvertCommand;
use Survos\ImportBundle\Command\ImportEntitiesCommand;
use Survos\ImportBundle\Command\ImportExportCsvCommand;
use Survos\ImportBundle\Command\ImportFilesystemCommand;
use Survos\ImportBundle\Command\ImportProfileReportCommand;
use Survos\ImportBundle\EventListener\ExportCsvOnConvertFinishedListener;
use Survos\ImportBundle\Service\EntityClassResolver;
use Survos\ImportBundle\Service\CsvProfileExporter;
use Survos\ImportBundle\Service\LooseObjectMapper;
use Survos\ImportBundle\Service\Provider\RowProviderInterface;
use Survos\ImportBundle\Service\Provider\RowProviderRegistry;
use Survos\ImportBundle\Service\RowNormalizer;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


class SurvosImportBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        $builder->autowire(RowProviderRegistry::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ;

        $builder->autowire(RowNormalizer::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
        ;

        $builder->registerForAutoconfiguration(RowProviderInterface::class)
            ->addTag('survos.import.row_provider');

        $builder->autowire(\Survos\ImportBundle\Service\Provider\CsvRowProvider::class)->setAutoconfigured(true);
        $builder->autowire(\Survos\ImportBundle\Service\Provider\JsonRowProvider::class)->setAutoconfigured(true);
        $builder->autowire(\Survos\ImportBundle\Service\Provider\JsonlRowProvider::class)->setAutoconfigured(true);
        $builder->autowire(\Survos\ImportBundle\Service\Provider\JsonDirRowProvider::class)->setAutoconfigured(true);


        $builder->registerForAutoconfiguration(RowProviderInterface::class)
            ->addTag('survos.import.row_provider');

            $builder->autowire(ImportEntitiesCommand::class)
                ->setPublic(true)
                ->setAutoconfigured(true)
                ->setArgument('$dataDir', $config['dir'])
                ->addTag('console.command');
            // @todo: inject each service properly

        $builder->autowire(ImportConvertCommand::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setArgument('$dataDir', $config['dir'])
            ->addTag('console.command');

        $builder->autowire(ImportProfileReportCommand::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setArgument('$dataDir', $config['dir'])
            ->addTag('console.command');

        $builder->autowire(ImportExportCsvCommand::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setArgument('$dataDir', $config['dir'])
            ->addTag('console.command');

        $builder->autowire(ImportBrowseCommand::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->addTag('console.command');

        $builder->autowire(ImportFilesystemCommand::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->addTag('console.command');

        $builder->autowire(CsvProfileExporter::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(ExportCsvOnConvertFinishedListener::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        // Register adapter for data-bundle integration if data-bundle is available
        if (class_exists(\Museado\DataBundle\Service\DataPaths::class)) {
            $builder->autowire(\Survos\ImportBundle\Service\DataPathsFactoryAdapter::class)
                ->setPublic(true)
                ->setAutoconfigured(true);
                
            // Alias the adapter to the interface
            $builder->setAlias(\Survos\ImportBundle\Contract\DatasetPathsFactoryInterface::class, \Survos\ImportBundle\Service\DataPathsFactoryAdapter::class);
        }

        // @todo: inject each service properly

        $builder->autowire(LooseObjectMapper::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ;


    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('dir')->info("The default directory for data files")->defaultValue('data')->end()
            ->end();
    }

}
