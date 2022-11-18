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
    private string $column;

    /**
     * Create a new filter.
     *
     * @param string $name
     * @param string|null $column
     * @param string|null $class
     * @return FilterOperator
     */
    public static function make(string $name, string $column = null, ?string $class = null): self
    {
        return new static($name, $column, $class);
    }

    /**
     * FilterOperator constructor.
     *
     * @param string $name
     * @param string|null $column
     * @param string|null $model
     */
    public function __construct(private string $name, string $column = null, private ?string $class = null)
    {
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

    private function addQueryFilters($query, string $column, string $operator, mixed $value)
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

    private function getRelationshipTableName($class, $relationshipName)
    {
        if(empty($this->class))
            throw new UnprocessableEntityHttpException("Bad filters - model is required for relationship columns");
        
        if (!method_exists($this->class, $relationshipName))
            throw new UnprocessableEntityHttpException("Bad filters - $relationshipName relation does not exist");

        $class = new $class;

        return $class->$relationshipName()->getRelated()->getTable();
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
                return $this->addQueryFilters($query, $column, $value['operator'], $value['value']);
            case 2:
                $relationshipName = $relation[0];
                $relationshipColumn = $relation[1];

                $relationshipTableName = $this->getRelationshipTableName($this->class, $relationshipName);

                $query = $query->hasByNonDependentSubquery($relationshipName, function ($query) use ($relationshipTableName, $relationshipColumn, $value) {
                    $this->addQueryFilters($query, $relationshipTableName.'.'.$relationshipColumn, $value['operator'], $value['value']);
                });
                return $query;
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