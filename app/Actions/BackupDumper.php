<?php

namespace App\Actions;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackupDumper
{
    protected Builder $query;

    protected Collection $records;

    protected array $results = [];

    public static function make(Builder $query, $columns = '*')
    {
        $instance = new static;

        $instance->records = $query->select($columns)->get();

        $instance->query = $query;
        $grammar = $instance->query->getGrammar();
        $connection = $instance->query->getConnection();

        foreach ($instance->records as $item) {
            $bindings = (array) $item;
            $compileSql = $grammar->compileInsert($instance->query, $bindings);

            $instance->results[] = $grammar->substituteBindingsIntoRawSql(
                $compileSql,
                $connection->prepareBindings($bindings)
            ).";\n";
        }

        return $instance;
    }

    public function delete(array $bindings)
    {
        $query = $this->query;
        $grammar = $query->getGrammar();

        $this->results = [
            $grammar->substituteBindingsIntoRawSql($grammar->compileDelete(with(clone $query)->where($bindings)), $bindings).";\n\n",
            ...($this->results ?? []),
        ];

        return $this;
    }

    public function compile()
    {
        return implode('', $this->results);
    }
}
