<?php
declare(strict_types=1);
/** generated from /home/tac/g/sites/survos/vendor/survos/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\ImportBundle;

use Survos\ImportBundle\Command\ImportBrowseCommand;
use Survos\ImportBundle\Command\ImportConvertCommand;
use Survos\ImportBundle\Command\ImportDirCommand;
use Survos\ImportBundle\Command\ImportEntitiesCommand;
use Survos\ImportBundle\Command\ImportExportCsvCommand;
use Survos\ImportBundle\Command\ImportFilesystemCommand;
use Survos\ImportBundle\Command\ImportProfileReportCommand;
use Survos\ImportBundle\Compiler\FetchAwareEntityPass;
use Survos\ImportBundle\EventListener\DtoMapRecordListener;
use Survos\ImportBundle\EventListener\ExportCsvOnConvertFinishedListener;
use Survos\ImportBundle\EventListener\FetchPageCountUpdateListener;
use Survos\ImportBundle\EventListener\SampleImportDirEnrichmentListener;
use Survos\ImportBundle\MessageHandler\FetchPageMessageHandler;
use Survos\ImportBundle\Repository\FetchPageRepository;
use Survos\ImportBundle\Repository\FetchRecordRepository;
use Survos\ImportBundle\Service\DtoClassResolver;
use Survos\ImportBundle\Service\EntityClassResolver;
use Survos\ImportBundle\Service\CsvProfileExporter;
use Survos\ImportBundle\Service\DtoMapper;
use Survos\ImportBundle\Service\FetchAwareEntityRegistry;
use Survos\ImportBundle\Service\FetchRecordExporter;
use Survos\ImportBundle\Service\LooseObjectMapper;
use Survos\ImportBundle\Service\ProbeService;
use Survos\ImportBundle\Service\Provider\RowProviderInterface;
use Survos\ImportBundle\Service\Provider\RowProviderRegistry;
use Survos\ImportBundle\Service\RowNormalizer;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


class SurvosImportBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'SurvosImportBundle' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => \dirname(__DIR__) . '/src/Entity',
                            'prefix' => 'Survos\\ImportBundle\\Entity',
                            'alias' => 'Import',
                        ],
                    ],
                ],
            ]);
        }
    }

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

        $builder->autowire(ImportDirCommand::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->addTag('console.command');

        $builder->autowire(ProbeService::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(FetchPageMessageHandler::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(FetchPageRepository::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->addTag('doctrine.repository_service');

        $builder->autowire(FetchRecordRepository::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->addTag('doctrine.repository_service');

        $builder->autowire(FetchRecordExporter::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(FetchAwareEntityRegistry::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(FetchPageCountUpdateListener::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(CsvProfileExporter::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(ExportCsvOnConvertFinishedListener::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(SampleImportDirEnrichmentListener::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        // Register adapter for data-bundle integration if data-bundle is available
        if (class_exists(\Survos\DataBundle\Service\DataPaths::class)) {
            $builder->autowire(\Survos\ImportBundle\Service\DataPathsFactoryAdapter::class)
                ->setPublic(true)
                ->setAutoconfigured(true);
                
            // Alias the adapter to the interface
            $builder->setAlias(\Survos\ImportBundle\Contract\DatasetPathsFactoryInterface::class, \Survos\ImportBundle\Service\DataPathsFactoryAdapter::class);
        }

        // @todo: inject each service properly

        $builder->autowire(LooseObjectMapper::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(DtoMapper::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(DtoClassResolver::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setArgument('$explicit', $config['dto_mappings'])
            ->setArgument('$namespaceRoots', $config['dto_namespace_roots']);

        $builder->autowire(DtoMapRecordListener::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('dir')->info('Default directory for data files')->defaultValue('data')->end()
                ->arrayNode('dto_namespace_roots')
                    ->info('Namespace roots for convention-based DTO class resolution (e.g. App\\Dto)')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('dto_mappings')
                    ->info('Explicit dataset → FQCN mappings, overrides convention (e.g. mus/aust: App\\Dto\\Aust\\Obj)')
                    ->useAttributeAsKey('dataset')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new FetchAwareEntityPass());
    }

}
