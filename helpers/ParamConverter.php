<?php
namespace tigrov\kendoui\helpers;


use yii\db\ActiveRecord;

class ParamConverter
{
    const DEFAULT_FILTER_LOGIC = 'and';

    /**
     * @var array query operators for filter by string fields
     */
    const STRING_OPERATORS = [
        'eq' => 'like',
        'neq' => 'not like',
        'doesnotcontain' => 'not like',
        'contains' => 'like',
        'startswith' => 'like',
        'endswith' => 'like',
    ];

    /**
     * @var array query operators for filter by number fields
     */
    const NUMBER_OPERATORS = [
        'eq' => '=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'neq' => '!=',
    ];

    /**
     * @var array query functions for aggregate by fields
     */
    const AGGREGATE_FUNCTIONS = [
        'average' => 'AVG',
        'min' => 'MIN',
        'max' => 'MAX',
        'count' => 'COUNT',
        'sum' => 'SUM',
    ];

    /**
     * Convert sort query from Kendo UI to Yii2 orderBy columns
     *
     * @param array $sort usually values of $_POST['sort']
     * @param ActiveRecord $model model for generation orderBy columns
     * @return array columns for Query::orderBy()
     */
    public static function sort(array $sort, ActiveRecord $model)
    {
        $attributes = $model->attributes();

        $columns = [];
        foreach($sort as $s) {
            if (!empty($s['field']) && in_array($s['field'], $attributes)
                && !empty($s['dir']) && in_array($s['dir'], ['asc', 'desc'], true)
            ) {
                $columns[$s['field']] = $s['dir'] == 'asc' ? SORT_ASC : SORT_DESC;
            }
        }

        return $columns;
    }

    /**
     * Convert filter query from Kendo UI to Yii2 where condition
     *
     * @param array $filter usually values of $_POST['filter']
     * @param ActiveRecord $model model for generation where condition
     * @return array|null where condition for Query::where()
     */
    public static function filter(array $filter, ActiveRecord $model)
    {
        if (!empty($filter['filters']) && is_array($filter['filters'])) {
            $logic = in_array($filter['logic'], ['or', 'and'], true) ? $filter['logic'] : static::DEFAULT_FILTER_LOGIC;

            $where = [];
            foreach ($filter['filters'] as $flt) {
                if ($condition = static::filter($flt, $model)) {
                    $where[] = $condition;
                }
            }

            if (count($where) > 1) {
                array_unshift($where, $logic);
            } else {
                $where = $where ? $where[0] : null;
            }

            return $where;
        }

        if (!empty($filter['field']) && in_array($filter['field'], $model->attributes())
            && isset($filter['value']) && !empty($filter['operator'])
        ) {
            $db = $model->getDb();
            $tableName = $model::tableName();
            $attribute = $tableName . '.' . $filter['field'];
            $value = $operator = null;

            if (isset(static::NUMBER_OPERATORS[$filter['operator']])) {
                $operator = static::NUMBER_OPERATORS[$filter['operator']];
                $value = static::parseDate($filter['value']);
                if ($value) {
                    $attribute = 'DATE(FROM_UNIXTIME(' . $db->quoteColumnName($attribute) . '))';
                } elseif (is_numeric($filter['value'])) {
                    $value = (float)$filter['value'];
                } elseif (in_array($filter['value'], ['true', 'false'])) {
                    $operator = $filter['value'] == 'false'
                        ? static::NUMBER_OPERATORS['eq']
                        : static::NUMBER_OPERATORS['neq'];
                    $value = 0;
                }
            }

            if ($value === null && isset(static::STRING_OPERATORS[$filter['operator']])) {
                $operator = static::STRING_OPERATORS[$filter['operator']];
                $value = $filter['value'];
                if ($filter['operator'] == 'contains' || $filter['operator'] == 'doesnotcontain') {
                    $value = "%$value%";
                } elseif ($filter['operator'] == 'startswith') {
                    $value = "$value%";
                } elseif ($filter['operator'] == 'endswith') {
                    $value = "%$value";
                }
            }

            return $value !== null ? [$operator, $attribute, $value] : null;
        }

        return null;
    }

    public static function parseDate($value)
    {
        $result = date_parse($value);
        return $result["error_count"] < 1 && checkdate($result['month'], $result['day'], $result['year'])
            ? $result['year']
            . '-' . str_pad($result['month'], 2, '0', STR_PAD_LEFT)
            . '-' . str_pad($result['day'], 2, '0', STR_PAD_LEFT)
            : null;
    }

    /**
     * Convert aggregate query from Kendo UI to DB aggregate functions
     *
     * @param array $aggregates usually values of $_POST['aggregates']
     * @param ActiveRecord $model model for generation aggregate functions
     * @return array aggregate functions for Query::select()
     */
    public static function aggregate(array $aggregates, ActiveRecord $model)
    {
        $db = $model->getDb();
        $attributes = $model->attributes();

        $functions = [];
        foreach ($aggregates as $aggregate) {
            if (!empty($aggregate['aggregate']) && isset(static::AGGREGATE_FUNCTIONS[$aggregate['aggregate']])
                && !empty($aggregate['field']) && in_array($aggregate['field'], $attributes)
            ) {
                $funcName = static::AGGREGATE_FUNCTIONS[$aggregate['aggregate']];
                $functions[] = $funcName . '(' . $db->quoteColumnName($aggregate['field']) . ') '
                    . ' AS ' . $db->quoteColumnName($aggregate['aggregate'] . '_' . $aggregate['field']);
            }
        }

        return $functions;
    }

    public static function aggregateValues($fields)
    {
        $values = [];
        foreach ($fields as $field => $value) {
            list($aggregate, $attribute) = explode('_', $field, 2);
            $values[$attribute][$aggregate] = $value;
        }

        return $values;
    }
}