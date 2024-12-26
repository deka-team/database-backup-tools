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

        try{
            foreach ($instance->records as $item) {
                $bindings = (array) $item;

                // cleanup bindings value, to handle "Strings with invalid UTF-8 byte sequences cannot be escaped."
                foreach($bindings as $key => $value){
                    $bindings[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }

                try{
                    $compileSql = $grammar->compileInsert($instance->query, $bindings);
        
                    $instance->results[] = $grammar->substituteBindingsIntoRawSql(
                        $compileSql,
                        $connection->prepareBindings($bindings)
                    ).";\n";
                }catch(\Exception $e){
                    dd($query, $bindings, $e->getMessage());
                }
            }
        }catch(\Exception $e){
            dd($query, $columns, $e->getMessage());
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
