<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Commands;

use Frolax\VectorStore\VectorStoreManager;
use Illuminate\Console\Command;

class CreateIndexCommand extends Command
{
    protected $signature = 'vector:create-index
        {name : The name of the index to create}
        {dimensions : The vector dimensions}
        {--store= : The vector store to use}
        {--metric=cosine : The distance metric (cosine, l2, ip)}';

    protected $description = 'Create a new index in the vector store';

    public function handle(VectorStoreManager $manager): int
    {
        $name = $this->argument('name');
        $dimensions = (int) $this->argument('dimensions');
        $storeName = $this->option('store');
        $metric = $this->option('metric');

        $store = $manager->store($storeName);

        $this->info("Creating index [{$name}] with {$dimensions} dimensions (metric: {$metric})...");

        $store->createIndex($name, $dimensions, ['metric' => $metric]);

        $this->info("Index [{$name}] created successfully.");

        return self::SUCCESS;
    }
}
