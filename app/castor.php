<?php

use Castor\Attribute\AsTask;

use function Castor\{io, run, fs, variable};

// Configuration - can be overridden via castor.yaml or environment

#[AsTask('setup', description: 'Setup bundles and directories')]
function setup(): void
{
    io()->title('Installing required bundles');
    if (!fs()->exists('.env.local')) {
        $dbUrl = 'DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"';

        fs()->appendToFile('.env.local', $dbUrl . PHP_EOL);
        io()->success('Created .env.local with SQLite configuration');
    } else {
        io()->note('.env.local already exists');
    }

    run('composer config extra.symfony.allow-contrib true');
    run('composer req survos/import-bundle easycorp/easyadmin-bundle:4.x-dev');
}

#[AsTask('fetch-data', description: 'Fetch the data and unzip it')]
function fetch(): void
{
    io()->title('Importing product data');
    run('mkdir -p data');
    run('curl -L -o data/movies.csv.gz https://github.com/metarank/msrd/raw/master/dataset/movies.csv.gz');
    run('gunzip data/movies.csv.gz');
}

#[AsTask('make-entity', description: 'Create the entity from the csv')]
function make_entity(): void
{
    run('bin/console code:entity Movie --file=data/movies.csv');
    run('bin/console d:sc:update --force');
}

#[AsTask('import', description: 'Fetch the data and unzip it')]
function import_data(): void
{
    io()->title('Importing product data');
    run('bin/console import:entities Movie --file data/movies.csv --limit 500');
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
    make_entity();
    fetch();
    import_data();
    open();

    io()->success('Demo application built successfully!');
    io()->note('Visit the opened browser to see the demo');
}
