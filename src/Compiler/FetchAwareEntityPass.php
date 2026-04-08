<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Compiler;

use Survos\ImportBundle\Contract\FetchPagesInterface;
use Survos\ImportBundle\Entity\Traits\FetchPagesTrait;
use Survos\ImportBundle\Service\FetchAwareEntityRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FetchAwareEntityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $roots = $container->hasParameter('survos_import.fetch_entity_roots')
            ? (array) $container->getParameter('survos_import.fetch_entity_roots')
            : [];

        if ($roots === []) {
            $roots = [
                (string) $container->getParameter('kernel.project_dir') . '/src/Entity' => 'App',
            ];
        }

        $map = [];

        foreach ($roots as $dir => $prefix) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach ($this->scanPhpFiles($dir) as $file) {
                $fqcn = $this->classFromFile($file, $dir, $prefix);
                if (!$fqcn || !class_exists($fqcn)) {
                    continue;
                }

                try {
                    $rc = new \ReflectionClass($fqcn);
                } catch (\Throwable) {
                    continue;
                }

                if ($rc->isAbstract() || $rc->isTrait() || !$rc->implementsInterface(FetchPagesInterface::class)) {
                    continue;
                }

                if (!$this->classUsesTraitRecursive($rc, FetchPagesTrait::class)) {
                    throw new \LogicException(sprintf(
                        'Class %s implements %s but does not use %s.',
                        $fqcn,
                        FetchPagesInterface::class,
                        FetchPagesTrait::class,
                    ));
                }

                $providerCode = $fqcn::getFetchProviderCode();
                if ($providerCode === '') {
                    throw new \LogicException(sprintf('Class %s returned an empty fetch provider code.', $fqcn));
                }

                $map[$providerCode] = $fqcn;
            }
        }

        ksort($map);
        $container->setParameter('survos_import.fetch_entity_map', $map);

        if ($container->hasDefinition(FetchAwareEntityRegistry::class)) {
            $container->getDefinition(FetchAwareEntityRegistry::class)
                ->setArgument('$map', $map);
        }
    }

    /** @return iterable<string> */
    private function scanPhpFiles(string $baseDir): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }

    private function classFromFile(string $file, string $baseDir, string $prefix): ?string
    {
        $rel = ltrim(str_replace('\\', '/', substr($file, strlen($baseDir))), '/');
        if (!str_ends_with($rel, '.php')) {
            return null;
        }

        return $prefix . '\\' . str_replace('/', '\\', substr($rel, 0, -4));
    }

    private function classUsesTraitRecursive(\ReflectionClass $rc, string $traitFqcn): bool
    {
        do {
            if (in_array($traitFqcn, array_keys($rc->getTraits()), true)) {
                return true;
            }
            foreach ($rc->getTraits() as $trait) {
                if ($this->traitUsesTraitRecursive($trait, $traitFqcn)) {
                    return true;
                }
            }
            $rc = $rc->getParentClass();
        } while ($rc);

        return false;
    }

    private function traitUsesTraitRecursive(\ReflectionClass $trait, string $traitFqcn): bool
    {
        if ($trait->getName() === $traitFqcn) {
            return true;
        }
        foreach ($trait->getTraits() as $nested) {
            if ($this->traitUsesTraitRecursive($nested, $traitFqcn)) {
                return true;
            }
        }

        return false;
    }
}
