<?php

namespace Glueful\Database\Tools;

class QueryPatternRecognizer
{
    /**
     * @var array<string, array<string, mixed>> Collection of query patterns for analysis
     */
    private $patterns = [];

    /**
     * Initialize the pattern recognizer with default patterns
     */
    public function __construct()
    {
        $this->loadPatterns();
    }

    /**
     * Analyze a query for known patterns
     *
     * @param string $query The SQL query to analyze
     * @return array<string, array<string, string>> Matched patterns with descriptions and recommendations
     */
    public function recognizePatterns(string $query): array
    {
        $matches = [];

        foreach ($this->patterns as $patternName => $pattern) {
            if (preg_match($pattern['regex'], $query)) {
                $matches[$patternName] = [
                    'description' => $pattern['description'],
                    'recommendation' => $pattern['recommendation']
                ];
            }
        }

        return $matches;
    }

    /**
     * Load default query patterns
     *
     * @return void
     */
    private function loadPatterns(): void
    {
        $this->patterns = [
            'select_star' => [
                'regex' => '/SELECT\s+\*\s+FROM/i',
                'description' => 'Using SELECT * retrieves all columns which can be inefficient',
                'recommendation' => 'Specify only the columns you need for better performance'
            ],
            'missing_where_clause' => [
                'regex' => '/SELECT.+FROM.+(?!WHERE)(?:ORDER|GROUP|HAVING|LIMIT|PROCEDURE|INTO|FOR|LOCK|$)/is',
                'description' => 'Query without WHERE clause may return too many rows',
                'recommendation' => 'Add a WHERE clause to limit the result set'
            ],
            'like_wildcard_prefix' => [
                'regex' => '/WHERE\s+\w+\s+LIKE\s+[\'"]%/i',
                'description' => 'LIKE with leading wildcard cannot use indexes efficiently',
                'recommendation' => 'Avoid leading wildcards (%) in LIKE patterns when possible'
            ],
            'or_conditions' => [
                'regex' => '/WHERE.+\s+OR\s+.+=/is',
                'description' => 'OR conditions may prevent efficient index usage',
                'recommendation' => 'Consider using UNION or IN() instead of OR for better index utilization'
            ],
            'order_by_rand' => [
                'regex' => '/ORDER\s+BY\s+RAND\(\)/i',
                'description' => 'ORDER BY RAND() is extremely inefficient for large tables',
                'recommendation' => 'Consider alternative randomization approaches or limit with subqueries'
            ],
            'negation_in_where' => [
                'regex' => '/WHERE\s+\w+\s+NOT\s+IN|!=|<>|IS\s+NOT\s+NULL/i',
                'description' => 'Negation operators often prevent index usage',
                'recommendation' => 'Rewrite using positive conditions when possible'
            ],
            'function_on_indexed_column' => [
                'regex' => '/WHERE\s+\w+\(\w+\)/i',
                'description' => 'Using functions on indexed columns prevents index usage',
                'recommendation' => 'Move functions to the right side of the operator when possible'
            ],
            'implicit_conversion' => [
                'regex' => '/WHERE\s+\w+\s+(?:=|<>|!=|>|<|>=|<=)\s+[\'"].+[\'"]/i',
                'description' => 'Potential type conversion in WHERE clause',
                'recommendation' => 'Ensure consistent data types in comparisons'
            ],
            'distinct_query' => [
                'regex' => '/SELECT\s+DISTINCT/i',
                'description' => 'DISTINCT can be resource-intensive',
                'recommendation' => 'Consider if DISTINCT is truly necessary or if it can be handled at the ' .
                    'application level'
            ],
            'subquery_in_select' => [
                'regex' => '/SELECT.+\(\s*SELECT/is',
                'description' => 'Subquery in SELECT clause may execute for each row',
                'recommendation' => 'Consider JOIN or temporary tables instead of subqueries in SELECT'
            ]
        ];
    }

    /**
     * Add a custom pattern to the recognizer
     *
     * @param string $name Pattern name/identifier
     * @param string $regex Regular expression to match the pattern
     * @param string $description Description of the pattern
     * @param string $recommendation Recommended action to improve the query
     * @return self
     */
    public function addPattern(string $name, string $regex, string $description, string $recommendation): self
    {
        $this->patterns[$name] = [
            'regex' => $regex,
            'description' => $description,
            'recommendation' => $recommendation
        ];

        return $this;
    }

    /**
     * Remove a pattern from the recognizer
     *
     * @param string $name Pattern name to remove
     * @return self
     */
    public function removePattern(string $name): self
    {
        if (isset($this->patterns[$name])) {
            unset($this->patterns[$name]);
        }

        return $this;
    }

    /**
     * Get all registered patterns
     *
     * @return array<string, array<string, mixed>> All registered patterns
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Reset patterns to default
     *
     * @return self
     */
    public function resetPatterns(): self
    {
        $this->patterns = [];
        $this->loadPatterns();

        return $this;
    }
}
