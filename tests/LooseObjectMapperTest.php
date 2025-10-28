<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Tests;

use Survos\ImportBundle\Tests\Entity\Sample;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\ImportBundle\Service\LooseObjectMapper;
use Symfony\Bridge\Doctrine\PropertyInfo\DoctrineExtractor;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\TypeInfo\Type;

#[CoversClass(LooseObjectMapper::class)]
final class LooseObjectMapperTest extends TestCase
{
    private function mapperWithStubbedTypes(array $map): LooseObjectMapper
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $mapper = new LooseObjectMapper(
            entityManager: $em,
            pa: null,
            nc: new CamelCaseToSnakeCaseNameConverter(),
        );

        $extractor = $this->createMock(DoctrineExtractor::class);
        $extractor->method('getType')->willReturnCallback(
            function (string $class, string $property) use ($map) {
                return $map[$property] ?? null;
            }
        );

        $refl = new \ReflectionClass($mapper);
        $prop = $refl->getProperty('doctrineExtractor');
        $prop->setAccessible(true);
        $prop->setValue($mapper, $extractor);

        return $mapper;
    }

    private function sampleTypeMap(): array
    {
        return [
            'id'        => Type::int(),
            'tags'      => Type::array(),
            'codes'     => Type::array(),
            'rating'    => Type::float(),
            'isActive'  => Type::bool(),
            'createdAt' => Type::object(DateTimeImmutable::class),
            'meta'      => Type::object('array'),
            'notes'     => Type::string(),
        ];
    }

    public function testDelimitedAndJsonArrayCoercion(): void
    {
        $mapper = $this->mapperWithStubbedTypes($this->sampleTypeMap());

        $data = [
            'TAGS' => 'a|b| c ',
            'codes' => "['x','y','z']",
        ];

        $entity = new Sample();
        $mapper->mapInto($data, $entity);

        $this->assertSame(['a','b','c'], $entity->tags);
        $this->assertSame(['x','y','z'], $entity->codes);
    }

    public function testBooleanCoercion(): void
    {
        $mapper = $this->mapperWithStubbedTypes($this->sampleTypeMap());

        $entity = new Sample();

        $mapper->mapInto(['is_active' => 'true'], $entity);
        $this->assertTrue($entity->isActive);

        $mapper->mapInto(['is_active' => 'false'], $entity);
        $this->assertFalse($entity->isActive);

        $mapper->mapInto(['is_active' => '1'], $entity);
        $this->assertTrue($entity->isActive);

        $mapper->mapInto(['is_active' => 0], $entity);
        $this->assertFalse($entity->isActive);
    }

    public function testNumericCoercion(): void
    {
        $mapper = $this->mapperWithStubbedTypes($this->sampleTypeMap());

        $entity = new Sample();
        $mapper->mapInto(['rating' => '3.14'], $entity);
        $this->assertSame(3.14, $entity->rating);

        $mapper->mapInto(['rating' => '0004'], $entity);
        $this->assertSame(4.0, $entity->rating);
    }

    public function testDatetimeCoercionVariants(): void
    {
        $mapper = $this->mapperWithStubbedTypes($this->sampleTypeMap());
        $entity = new Sample();

        // ISO 8601
        $mapper->mapInto(['created_at' => '2024-02-03T04:05:06Z'], $entity);
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->createdAt);
        $this->assertSame('2024-02-03T04:05:06+00:00', $entity->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('c'));

        // Date only (YYYY-MM-DD)
        $mapper->mapInto(['created_at' => '2024-12-31'], $entity);
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->createdAt);
        $this->assertSame('2024-12-31T00:00:00+00:00', $entity->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('c'));

        // US m/d/yy (-> 20xx heuristic)
        $mapper->mapInto(['created_at' => '7/4/24'], $entity);
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->createdAt);
        $this->assertSame('2024-07-04T00:00:00+00:00', $entity->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('c'));

        // Epoch seconds (midnight UTC for 2024-05-03)
        $ts = 1714694400; // 2024-05-03T00:00:00Z
        $mapper->mapInto(['created_at' => (string)$ts], $entity);
        $this->assertSame('2024-05-03T00:00:00+00:00', $entity->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('c'));

        // Epoch milliseconds
        $ms = '1714694400000';
        $mapper->mapInto(['created_at' => $ms], $entity);
        $this->assertSame('2024-05-03T00:00:00+00:00', $entity->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function testJsonObjectCoercion(): void
    {
        $mapper = $this->mapperWithStubbedTypes($this->sampleTypeMap());

        $entity = new Sample();
        $mapper->mapInto([
            'meta' => "{'a': 1, 'b': 'two'}",
        ], $entity);

        $this->assertIsArray($entity->meta);
        $this->assertSame(1, $entity->meta['a']);
        $this->assertSame('two', $entity->meta['b']);
    }

    public function testNullLiterals(): void
    {
        $mapper = $this->mapperWithStubbedTypes($this->sampleTypeMap());
        $entity = new Sample();

        $mapper->mapInto([
            'notes' => 'n/a',
            'rating' => 'null',
            'tags' => '',
        ], $entity, context: ['null_literals' => ['null','n/a','']]);

        $this->assertNull($entity->notes);
        $this->assertNull($entity->rating);

        // NOTE: tags is declared as non-nullable array; empty input should leave it as []
        $this->assertSame([], $entity->tags);
    }

    public function testKeyNormalizationAndIgnore(): void
    {
        $mapper = $this->mapperWithStubbedTypes($this->sampleTypeMap());
        $entity = new Sample();

        $mapper->mapInto([
            'ID' => '123',
            'Is_Active' => 'yes',
            'RATING' => '4.5',
        ], $entity, ignored: ['id']);

        $this->assertNull($entity->id);
        $this->assertTrue($entity->isActive);
        $this->assertSame(4.5, $entity->rating);
    }
}
