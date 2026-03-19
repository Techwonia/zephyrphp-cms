<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * Lightweight filter condition collector for compound (AND/OR) queries.
 *
 * Used inside EntryQuery closures:
 *   ->where(function(FilterGroup $q) {
 *       $q->where('status', 'published')->orWhere('featured', 1);
 *   })
 *
 * Conditions inside a group are combined with AND by default.
 * orWhere/orWhereIn/etc. add OR-joined conditions.
 */
class FilterGroup
{
    /** @var array<int, array{boolean: string, type: string, field?: string, value?: mixed, values?: array, min?: mixed, max?: mixed}> */
    private array $conditions = [];

    public function where(string $field, mixed $value): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'eq', 'field' => $field, 'value' => $value];
        return $this;
    }

    public function orWhere(string $field, mixed $value): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'eq', 'field' => $field, 'value' => $value];
        return $this;
    }

    public function whereIn(string $field, array $values): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'in', 'field' => $field, 'values' => array_values($values)];
        return $this;
    }

    public function orWhereIn(string $field, array $values): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'in', 'field' => $field, 'values' => array_values($values)];
        return $this;
    }

    public function whereNot(string $field, mixed $value): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'neq', 'field' => $field, 'value' => $value];
        return $this;
    }

    public function orWhereNot(string $field, mixed $value): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'neq', 'field' => $field, 'value' => $value];
        return $this;
    }

    public function whereBetween(string $field, mixed $min, mixed $max): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'between', 'field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    public function orWhereBetween(string $field, mixed $min, mixed $max): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'between', 'field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    public function whereNull(string $field): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'null', 'field' => $field];
        return $this;
    }

    public function orWhereNull(string $field): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'null', 'field' => $field];
        return $this;
    }

    public function whereNotNull(string $field): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'not_null', 'field' => $field];
        return $this;
    }

    public function orWhereNotNull(string $field): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'not_null', 'field' => $field];
        return $this;
    }

    public function whereLike(string $field, string $pattern): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'like', 'field' => $field, 'value' => $pattern];
        return $this;
    }

    public function orWhereLike(string $field, string $pattern): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'like', 'field' => $field, 'value' => $pattern];
        return $this;
    }

    public function whereNotLike(string $field, string $pattern): static
    {
        $this->conditions[] = ['boolean' => 'AND', 'type' => 'not_like', 'field' => $field, 'value' => $pattern];
        return $this;
    }

    public function orWhereNotLike(string $field, string $pattern): static
    {
        $this->conditions[] = ['boolean' => 'OR', 'type' => 'not_like', 'field' => $field, 'value' => $pattern];
        return $this;
    }

    /**
     * Serialize conditions for passing to SchemaManager.
     *
     * @return array<int, array>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Check if this group has any conditions.
     */
    public function isEmpty(): bool
    {
        return empty($this->conditions);
    }
}
