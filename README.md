# Survos Import Bundle

Symfony Bundle that provides some tools for importing data.

In particular, this bundle, along with its companion code generator, makes it really easy to import CSV file into a simple (e.g. no relations) entity.

I use it as a first pass of getting data into the system.

In short, install the bundles 

```bash
composer req survos/import-bundle
composer req --dev survos/code-bundle
```

First, create an entity class by inspecting the first line of a CSV file

```bash
bin/console code:entity Movie --file=data/movies.csv
```
The entity has property names that loosely match the CSV headers.  (Loosely, e.g. "First Name" becomes $firstName in the entity).  

```bash
bin/console import:entities Movie --file data/movies.csv --limit 500 
```



Complete app, using ez-admin to view the data

## Prerequisites

* symfony CLI
* curl
* PHP 8.4 (the demo uses property hooks)
* gunzip (because the data we're downloading for the demo is gzipped)

```bash
symfony new import-demo --webapp  && cd import-demo
composer config extra.symfony.allow-contrib true
echo "DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db" > .env.local
symfony server:start -d
composer req --dev survos/code-bundle
composer req survos/import-bundle league/csv
composer req easycorp/easyadmin-bundle:4.x-dev
mkdir -p data
curl -L -o data/movies.csv.gz https://github.com/metarank/msrd/raw/master/dataset/movies.csv.gz
gunzip data/movies.csv.gz
# sanity check
head -n 2 data/movies.csv
bin/console code:entity Movie --file=data/movies.csv
bin/console d:sch:update --force
bin/console import:entities Movie --file data/movies.csv --limit 500 
bin/console make:admin:dashboard -n
bin/console make:admin:crud App\\Entity\\Movie -n
```

For reasons I don't quite understand, clearing the cache doesn't work if it's included above, so run it separately before opening the page

````bash
bin/console cache:clear
bin/console cache:pool:clear cache.app
symfony open:local --path=/admin/movie
```



