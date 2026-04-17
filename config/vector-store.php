<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Vector Store
    |--------------------------------------------------------------------------
    |
    | The default vector store driver to use when no store is explicitly
    | specified. This value should correspond to one of the stores defined
    | in the "stores" array below.
    |
    */

    'default' => env('VECTOR_STORE', 'pinecone'),

    /*
    |--------------------------------------------------------------------------
    | Vector Stores
    |--------------------------------------------------------------------------
    |
    | Here you may configure each vector store driver your application uses.
    | Each store requires a "driver" key that matches one of the supported
    | drivers: pinecone, qdrant, weaviate, chroma, milvus, pgvector.
    |
    */

    'stores' => [

        'pinecone' => [
            'driver' => 'pinecone',
            'api_key' => env('PINECONE_API_KEY'),
            'host' => env('PINECONE_HOST'),
            'namespace' => env('PINECONE_NAMESPACE', ''),
        ],

        'qdrant' => [
            'driver' => 'qdrant',
            'host' => env('QDRANT_HOST', 'http://localhost:6333'),
            'api_key' => env('QDRANT_API_KEY'),
            'collection' => env('QDRANT_COLLECTION', 'default'),
        ],

        'weaviate' => [
            'driver' => 'weaviate',
            'host' => env('WEAVIATE_HOST', 'http://localhost:8080'),
            'api_key' => env('WEAVIATE_API_KEY'),
            'class' => env('WEAVIATE_CLASS', 'Document'),
        ],

        'chroma' => [
            'driver' => 'chroma',
            'host' => env('CHROMA_HOST', 'http://localhost:8000'),
            'collection' => env('CHROMA_COLLECTION', 'default'),
            'auth' => [
                'type' => env('CHROMA_AUTH_TYPE', 'none'),
                'token' => env('CHROMA_AUTH_TOKEN'),
            ],
        ],

        'milvus' => [
            'driver' => 'milvus',
            'host' => env('MILVUS_HOST', 'http://localhost:19530'),
            'token' => env('MILVUS_TOKEN'),
            'collection' => env('MILVUS_COLLECTION', 'default'),
        ],

        'pgvector' => [
            'driver' => 'pgvector',
            'connection' => env('PGVECTOR_CONNECTION', 'pgsql'),
            'table' => env('PGVECTOR_TABLE', 'vector_records'),
            'dimensions' => env('PGVECTOR_DIMENSIONS', 1536),
            'metric' => env('PGVECTOR_METRIC', 'cosine'), // cosine|l2|ip
        ],

    ],
];
