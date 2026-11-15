<?php

namespace App\Models\Site;

use App\Exceptions\ReadOnlySiteModelException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query builder for site models that refuses every bulk write.
 *
 * @template TModel of SiteModel
 *
 * @extends Builder<TModel>
 */
class ReadOnlyBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $this->refuse('update');

        return parent::update($values);
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $this->refuse('insert');

        return $this->toBase()->insert($values);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->refuse('upsert');

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @return mixed
     */
    public function delete()
    {
        $this->refuse('delete');

        return parent::delete();
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->refuse('increment');

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->refuse('decrement');

        return parent::decrement($column, $amount, $extra);
    }

    private function refuse(string $operation): void
    {
        if (! SiteModel::writesAllowed()) {
            throw ReadOnlySiteModelException::for($this->model::class, $operation);
        }
    }
}
