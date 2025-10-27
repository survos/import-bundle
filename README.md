# Survos Import Bundle

Symfony Bundle that provides some tools for importing data.



```bash
composer req survos/import-bundle
```

Quick app using meili-bundle, to leverage the ez-admin tools

```bash
symfony new import-demo --webapp --version=next && cd import-demo
echo "DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db" > .env.local
composer req --dev survos/code-bundle
composer req survos/import-bundle
composer req survos/meili-bundle
wget ...
bin/console code:entity --meili ... 
bin/console survos:import ...

composer req easycorp/easyadmin-bundle

bin/console make:admin:dashboard
bin/console make:admin:crud



