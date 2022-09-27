<?php

declare(strict_types=1);

namespace Endoedgar\LaravelJsonApiFilterOperator\Filters;

use Illuminate\Database\Eloquent\Builder;
use LaravelJsonApi\Core\Support\Str;
use LaravelJsonApi\Eloquent\Contracts\Filter;
use LaravelJsonApi\Eloquent\Filters\Concerns\DeserializesToArray;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class FilterOperator implements Filter
{
    private $allowedOperators = ['=', '>', '<', '>=', '<=', '<>', 'between', 'in', 'null', 'not_null', 'contains'];

    use DeserializesToArray;

    /**
     * @var string
     */
    private string $name;

    /**
     * @var string
     */
    private string $column;

    /**
     * Create a new filter.
     *
     * @param string $name
     * @param string|null $column
     * @return FilterOperator
     */
    public static function make(string $name, string $column = null): self
    {
        return new static($name, $column);
    }

    /**
     * FilterOperator constructor.
     *
     * @param string $name
     * @param string|null $column
     */
    public function __construct(string $name, string $column = null)
    {
        $this->name = $name;
        $this->column = $column ?: Str::underscore($name);
    }

    /**
     * Get the key for the filter.
     *
     * @return string
     */
    public function key(): string
    {
        return $this->name;
    }

    protected function validate($value)
    {
        if(!is_array($value))
            throw new BadRequestHttpException('Expecting filter ' . $this->name . ' to be an array.');

        if(!array_key_exists('operator', $value))
            throw new BadRequestHttpException('Expecting filter ' . $this->name . ' to have an operator.');

        if(!array_key_exists('value', $value))
            throw new BadRequestHttpException('Expecting filter ' . $this->name . ' to have a value.');

        if (!in_array($value['operator'], $this->allowedOperators)) {
            throw new BadRequestHttpException("Bad filter operator, operator can be one of " . implode(", ", $this->allowedOperators));
        };
    }

    private function getValueAsArray(?string $value) : array
    {
        if(is_null($value))
            return [];

        return explode(',', $value);
    }

    private function addQueryFilters(Builder $query, string $column, string $operator, mixed $value) : Builder
    {
        switch($operator) {
            case 'between':
                return $query->whereBetween($column, $this->getValueAsArray($value));
            case 'in':
                return $query->whereIn($column, $this->getValueAsArray($value));
            case 'null':
                return $query->whereNull($column);
            case 'not_null':
                return $query->whereNotNull($column);
            case 'contains':
                return $query->where($column, 'like', '%' . $value . '%');
            default:
                return $query->where($column, $operator, $value);
        }
    }

    /**
     * Apply the filter to the query.
     *
     * @param Builder $query
     * @param array $value
     * @return Builder
     */
    public function apply($query, $value)
    {
        $this->validate($value);

        $column = $this->column;

        $relation = explode('.', $column);

        switch(count($relation)) {
            case 1:
                return $this->addQueryFilters($query, $column, $value['value'], $value['value']);
            case 2:
                $relationshipName = $relation[0];
                $relationshipColumn = $relation[1];
                
                if (!method_exists($this, $relationshipName))
                    throw new UnprocessableEntityHttpException("Bad filters - $relationshipName relation does not exist");

                return $query->hasByNonDependentSubquery($relationshipName, function ($query) use ($relationshipName, $relationshipColumn, $value) {
                    $this->addQueryFilters($query, $relationshipName.'.'.$relationshipColumn, $value['operator'], $value['value']);
                });
            default:
                throw new BadRequestHttpException('Operator Filter for ' . $this->name . ' doesn\'t support relationships of relationships yet.');
        }
    }

    /**
     * @inheritDoc
     */
    public function isSingular(): bool
    {
        return false;
    }
}
