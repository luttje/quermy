<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesTextColumnTypePatterns
{
    /**
     * Lowercase substrings that, when found in a column's type string, indicate
     * the column holds text and can be searched with LIKE.
     *
     * @return list<string>
     */
    public function textColumnTypePatterns(): array;
}
