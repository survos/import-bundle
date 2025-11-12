<?php

use Castor\Attribute\AsTask;

use function Castor\{io, run, fs, variable};

// Configuration - can be overridden via castor.yaml or environment

#[AsTask('setup', description: 'Setup bundles and directories')]
function setup(): void
{
    io()->title('Installing required bundles');
    run('composer req survos/import-bundle easycorp/easyadmin-bundle:4.x-dev');
}

#[AsTask('database', description: 'Configure and initialize database')]
function database(): void
{
    io()->title('Configuring database');

    if (!fs()->exists('.env.local')) {
        $dbUrl = 'DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"';
        fs()->appendToFile('.env.local', $dbUrl . PHP_EOL);
        io()->success('Created .env.local with SQLite configuration');
    } else {
        io()->note('.env.local already exists');
    }

    io()->title('Creating database schema');
    run('bin/console doctrine:schema:update --force --dump-sql');
}


#[AsTask('import', description: 'Import demo data')]
function import(): void
{
    io()->title('Importing product data');
    run('bin/console app:import-products'); // Match your actual command name
}

#[AsTask('open', description: 'Start web server and open in browser')]
function open(): void
{
    io()->title('Starting Symfony server');
    run('symfony server:start -d');
    run('symfony open:local --path=/product'); // Adjust path as needed
}

#[AsTask('build', description: 'Complete demo setup (all steps)')]
function build(): void
{
    io()->section('Building complete demo application');

    setup();
    database();
    import();
    open();

    io()->success('Demo application built successfully!');
    io()->note('Visit the opened browser to see the demo');
}

#[AsTask('clean', description: 'Remove generated files and reset')]
function clean(): void
{
    if (!io()->confirm('This will remove generated files. Continue?', false)) {
        return;
    }

    io()->title('Cleaning up demo files');

    $filesToRemove = [
        'src/Entity/Product.php',
        'src/Repository/ProductRepository.php',
        'src/Command/ImportProductsCommand.php',
        'templates/products.html.twig',
        'var/data.db',
    ];

    foreach ($filesToRemove as $file) {
        if (fs()->exists($file)) {
            fs()->remove($file);
            io()->success("Removed {$file}");
        }
    }
}
