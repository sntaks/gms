<?php

namespace Database\Factories\Site;

use App\Models\Site\SiteModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * Base for Kadi site test factories. Site models are read-only, so only these
 * factories may insert rows, into the test schema (tests/Support/SiteSchema.php).
 *
 * @template TModel of SiteModel
 *
 * @extends Factory<TModel>
 */
abstract class SiteFactory extends Factory
{
    /**
     * @param  Collection<int, TModel>  $results
     */
    protected function store(Collection $results): void
    {
        SiteModel::allowingWrites(fn () => parent::store($results));
    }
}
