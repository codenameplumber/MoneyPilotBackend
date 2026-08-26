<?php

namespace App\Livewire\Concerns;

use App\Support\PageSize;
use Livewire\Attributes\Url;

/**
 * Sorting, paging and page-size for a console table.
 *
 * The prototype's datatable did this in JavaScript over an array already in the
 * browser. Here the same behaviour has to survive a server round-trip, so the
 * state lives in the URL: a moderator who sorts by "off by", pages to 3 and
 * sends somebody the link sends them the same view, and a Livewire update does
 * not silently reset them to page 1.
 *
 * Sorting is applied by the host component, because a column can be a database
 * column, a computed figure or a nested array key, and pretending otherwise
 * produces a helper that only works for the easy case.
 */
trait WithDataTable
{
    #[Url(as: 'sort')]
    public string $sortBy = '';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    #[Url(as: 'page')]
    public int $page = 1;

    #[Url(as: 'per')]
    public int $perPage = PageSize::DEFAULT;

    /**
     * Click a header.
     *
     * Same column toggles direction; a new column starts descending, because
     * on every table in this console the interesting rows are the big ones.
     */
    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $column;
            $this->sortDir = 'desc';
        }

        $this->page = 1;
    }

    public function setPerPage(int $size): void
    {
        $this->perPage = PageSize::clamp($size);
        $this->page    = 1;
    }

    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /** Any filter change must return to page 1, or the table looks empty. */
    public function updated($property): void
    {
        if (! in_array($property, ['page', 'sortBy', 'sortDir', 'perPage'], true)) {
            $this->page = 1;
        }
    }

    /**
     * Sort, then slice.
     *
     * @param  array    $rows
     * @param  callable $value  fn(array $row, string $column): mixed
     * @return array{rows:array,total:int,pages:int,from:int,to:int}
     */
    protected function paginate(array $rows, callable $value): array
    {
        if ($this->sortBy !== '') {
            $dir = $this->sortDir === 'asc' ? 1 : -1;

            usort($rows, function ($a, $b) use ($value, $dir) {
                $x = $value($a, $this->sortBy);
                $y = $value($b, $this->sortBy);

                // Strings compare case-insensitively; anything else numerically.
                $cmp = is_string($x) && is_string($y)
                    ? strcasecmp($x, $y)
                    : $x <=> $y;

                return $cmp * $dir;
            });
        }

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $this->perPage));

        // Clamp rather than 404. Deleting rows, or arriving on a stale link,
        // should not present an empty table with no way back.
        $page  = min(max(1, $this->page), $pages);
        $from  = ($page - 1) * $this->perPage;

        return [
            'rows'  => array_slice($rows, $from, $this->perPage),
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
            'from'  => $total ? $from + 1 : 0,
            'to'    => min($from + $this->perPage, $total),
        ];
    }
}
