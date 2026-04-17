<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Contracts;

use Frolax\VectorStore\Exceptions\FilterCompilationException;

interface FilterCompilerContract
{
    /**
     * Compile the normalised filter AST into the driver's native filter format.
     *
     * Each condition in the array follows this structure:
     *   ['field' => string, 'op' => string, 'value' => mixed]
     *
     * Supported operators: =, !=, >, >=, <, <=, in, not_in, between, null, not_null
     *
     * @param  array<int, array{field: string, op: string, value?: mixed}>  $conditions
     * @return mixed The compiled filter in driver-native format
     *
     * @throws FilterCompilationException
     */
    public function compile(array $conditions): mixed;
}
