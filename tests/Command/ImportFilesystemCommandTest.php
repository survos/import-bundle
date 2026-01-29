<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Survos\ImportBundle\Command\ImportFilesystemCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

class ImportFilesystemCommandTest extends TestCase
{
    public function testCommandHasCorrectAttributes(): void
    {
        $reflection = new \ReflectionClass(ImportFilesystemCommand::class);
        
        // Check class is final
        self::assertTrue($reflection->isFinal());
        
        // Check AsCommand attribute
        $asCommandAttributes = $reflection->getAttributes(AsCommand::class);
        self::assertCount(1, $asCommandAttributes);
        
        $asCommand = $asCommandAttributes[0]->newInstance();
        self::assertEquals('import:filesystem', $asCommand->name);
        self::assertStringContainsString('filesystem', $asCommand->description);
    }
    
    public function testInvokeMethodHasCorrectParameters(): void
    {
        $reflection = new \ReflectionClass(ImportFilesystemCommand::class);
        $invokeMethod = $reflection->getMethod('__invoke');
        
        $parameters = $invokeMethod->getParameters();
        
        // Should have 5 parameters: $io, $directory, $output, $extensions, $excludeDirs
        self::assertCount(5, $parameters);
        
        // Check first parameter has no attribute (SymfonyStyle)
        self::assertCount(0, $parameters[0]->getAttributes());
        
        // Check directory parameter has Argument attribute
        $directoryAttributes = $parameters[1]->getAttributes(Argument::class);
        self::assertCount(1, $directoryAttributes);
        
        // Check other parameters have Option attributes
        $outputAttributes = $parameters[2]->getAttributes(Option::class);
        self::assertCount(1, $outputAttributes);
        
        $extensionsAttributes = $parameters[3]->getAttributes(Option::class);
        self::assertCount(1, $extensionsAttributes);
    }
}