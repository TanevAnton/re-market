<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove `parts.search_vector` and `listings.search_vector`, and their GIN
 * indexes with them.
 *
 * THEY WERE NEVER POPULATED AND NEVER READ. They went in with the original
 * tables on 5 Sep as the start of a full-text search path that was then
 * overtaken: search ended up on aliases plus `pg_trgm` `word_similarity`, which
 * handles the misspellings and the Cyrillic that a tsvector would not — „ртх
 * 4070" and „RTX 4070" share not one character, so no amount of stemming bridges
 * them, and `Part::queryVariants()` folding to Latin is what actually solved it.
 *
 * Two tsvector columns with GIN indexes behind them that no trigger fills and no
 * query touches are worse than nothing:
 *
 *  - Every INSERT and UPDATE on the two hottest tables on the site maintains
 *    two indexes over a column that is always NULL.
 *  - They read as a working search path. The next person to look at „how does
 *    search work here" finds three of them, one of which is a decoy, and either
 *    wires the decoy up or spends an afternoon proving it is dead.
 *
 * Deciding NOT to build something is a decision worth committing. If full-text
 * is ever wanted, it comes back with a generated column and a trigger in one
 * migration — which is what should have happened the first time, rather than
 * leaving the column as a note to self in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DROP COLUMN takes the GIN index with it; naming the indexes here as
        // well would just be two statements that can disagree.
        DB::statement('ALTER TABLE parts    DROP COLUMN IF EXISTS search_vector');
        DB::statement('ALTER TABLE listings DROP COLUMN IF EXISTS search_vector');
    }

    /**
     * Put them back exactly as they were — still empty, still unread. `down()`
     * restores the previous state, it does not improve on it.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE parts    ADD COLUMN search_vector tsvector');
        DB::statement('ALTER TABLE listings ADD COLUMN search_vector tsvector');

        DB::statement('CREATE INDEX parts_search_idx    ON parts    USING GIN (search_vector)');
        DB::statement('CREATE INDEX listings_search_idx ON listings USING GIN (search_vector)');
    }
};
