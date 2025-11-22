<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

final class EntityClassResolver
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    /**
     * Accepts:
     *   - Fully qualified class ("App\Entity\Wam")
     *   - Short class name ("Wam")
     *   - Lowercase short name ("wam")
     *
     * Returns FQCN or throws if not found.
     */
    public function resolve(string $name): string
    {
        // If already FQCN and loadable → done.
        if (\class_exists($name)) {
            return $name;
        }

        // Try ucfirst
        $short = \ucfirst($name);

        // Ask doctrine for all known entity classes
        foreach ($this->registry->getManagers() as $em) {
            $metadata = $em->getMetadataFactory()->getAllMetadata();

            foreach ($metadata as $meta) {
                $class = $meta->getName();

                if (\str_ends_with($class, '\\' . $short)) {
                    return $class;
                }

                if (\basename(str_replace('\\', '/', $class)) === $short) {
                    return $class;
                }
            }
        }

        throw new RuntimeException(sprintf(
            'Cannot resolve entity class for "%s". Try using the full FQCN.',
            $name
        ));
    }
}
