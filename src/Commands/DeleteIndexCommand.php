<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Commands;

use Frolax\VectorStore\VectorStoreManager;
use Illuminate\Console\Command;

class DeleteIndexCommand extends Command
{
    protected $signature = 'vector:delete-index
        {name : The name of the index to delete}
        {--store= : The vector store to use}
        {--force : Skip confirmation}';

    protected $description = 'Delete an index from the vector store';

    public function handle(VectorStoreManager $manager): int
    {
        $name = $this->argument('name');
        $storeName = $this->option('store');

        if (! $this->option('force') && ! $this->confirm("Are you sure you want to delete index [{$name}]?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $store = $manager->store($storeName);

        $this->info("Deleting index [{$name}]...");

        $store->deleteIndex($name);

        $this->info("Index [{$name}] deleted successfully.");

        return self::SUCCESS;
    }
}
