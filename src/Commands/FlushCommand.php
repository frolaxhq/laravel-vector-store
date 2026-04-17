<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Commands;

use Frolax\VectorStore\VectorStoreManager;
use Illuminate\Console\Command;

class FlushCommand extends Command
{
    protected $signature = 'vector:flush
        {--store= : The vector store to use}
        {--force : Skip confirmation}';

    protected $description = 'Delete all records from the vector store (keeps indexes)';

    public function handle(VectorStoreManager $manager): int
    {
        $storeName = $this->option('store');

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to delete ALL records? Indexes will be preserved.')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $store = $manager->store($storeName);

        $this->info('Flushing all records...');

        // If the driver has a flush method, use it; otherwise this is driver-specific
        if (method_exists($store, 'flush')) {
            $store->flush();
        } else {
            $this->error('The selected driver does not support the flush operation.');

            return self::FAILURE;
        }

        $this->info('All records flushed successfully.');

        return self::SUCCESS;
    }
}
