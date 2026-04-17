<?php

declare(strict_types=1);

namespace Frolax\VectorStore;

use Frolax\VectorStore\Commands\CreateIndexCommand;
use Frolax\VectorStore\Commands\DeleteIndexCommand;
use Frolax\VectorStore\Commands\FlushCommand;
use Frolax\VectorStore\Commands\ListIndexesCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VectorStoreServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vector-store')
            ->hasConfigFile()
            ->hasMigration('create_vector_records_table')
            ->hasCommands([
                ListIndexesCommand::class,
                CreateIndexCommand::class,
                DeleteIndexCommand::class,
                FlushCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(VectorStoreManager::class, function ($app) {
            return new VectorStoreManager($app);
        });

        // Alias so the facade can resolve by class name
        $this->app->alias(VectorStoreManager::class, 'vector-store');
    }
}
