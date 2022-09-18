<?php

declare(strict_types=1);

namespace Endoedgar\LaravelJsonApiFilterOperator\Filters;

use Illuminate\Database\Eloquent\Builder;
use LaravelJsonApi\Core\Support\Str;
use LaravelJsonApi\Eloquent\Contracts\Filter;
use LaravelJsonApi\Eloquent\Filters\Concerns\DeserializesToArray;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FilterOperator implements Filter
{
    private $allowedOperators = ['=', '>', '<', '>=', '<=', '<>', 'between', 'in', 'null'];

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

        if(!isset($value['operator']))
            throw new BadRequestHttpException('Expecting filter ' . $this->name . ' to have an operator.');

        if(!isset($value['value']))
            throw new BadRequestHttpException('Expecting filter ' . $this->name . ' to have a value.');

        if (!in_array($value['operator'], $this->allowedOperators)) {
            throw new BadRequestHttpException("Bad filter operator, operator can be one of " . implode(", ", $this->allowedOperators));
        };
    }

    /**
     * Apply the filter to the query.
     *
     * @param Builder $query
     * @param mixed $value
     * @return Builder
     */
    public function apply($query, $value)
    {
        $this->validate($value);

        $column = $this->column;

        $relation = explode('.', $column);
        if (count($relation) !== 1)
            throw new BadRequestHttpException('Operator Filter for ' . $this->name . ' doesn\'t support relationships yet.');

        switch($value['operator']) {
            case 'between':
                $query->whereBetween($column, explode(',', $value['value']));
                break;
            case 'in':
                $query->whereIn($column, explode(',', $value['value']));
                break;
            case 'null':
                $query->whereNull($column);
                break;
            default:
                $query->where($column, $value['operator'], $value['value']);
                break;
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
