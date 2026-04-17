<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Commands;

use Frolax\VectorStore\VectorStoreManager;
use Illuminate\Console\Command;

class ListIndexesCommand extends Command
{
    protected $signature = 'vector:list-indexes {--store= : The vector store to use}';

    protected $description = 'List all indexes in the vector store';

    public function handle(VectorStoreManager $manager): int
    {
        $storeName = $this->option('store');
        $store = $manager->store($storeName);

        $this->info('Fetching indexes...');

        $indexes = $store->listIndexes();

        if (empty($indexes)) {
            $this->warn('No indexes found.');

            return self::SUCCESS;
        }

        $this->table(['Index Name'], array_map(fn ($name) => [$name], $indexes));

        return self::SUCCESS;
    }
}
