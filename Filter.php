<?php

use Symfony\Component\Yaml\Yaml;

class Filter
{
  private $synonyms = [];
  private $currentPos = 0;
  private $filterString = '';
  private $tree = null;

  public function __construct(string $synonymFile = null)
  {
    if($synonymFile !== null && file_exists($synonymFile))
      $this->synonyms = Yaml::parseFile($synonymFile);
  }

  public function parse(string $filterString): array
  {
    $this->filterString = $filterString;
    $this->currentPos = 0;
    return $this->parseExpression();
  }

  private function parseExpression(): array
  {
    $terms = [];
    $operator = null;

    while($this->currentPos < strlen($this->filterString))
    {
      $this->skipWhitespace();
      
      if($this->currentPos >= strlen($this->filterString))
        break;

      $char = $this->filterString[$this->currentPos];

      if($char === '(')
      {
        $this->currentPos++;
        $subExpr = $this->parseExpression();
        if($this->currentPos < strlen($this->filterString) && $this->filterString[$this->currentPos] === ')')
          $this->currentPos++;
        $terms[] = $subExpr;
      }
      else if(strtolower(substr($this->filterString, $this->currentPos, 3)) === 'and')
      {
        $this->currentPos += 3;
        $operator = 'and';
      }
      else if(strtolower(substr($this->filterString, $this->currentPos, 2)) === 'or')
      {
        $this->currentPos += 2;
        $operator = 'or';
      }
      else
      {
        $term = $this->parseTerm();
        if($term !== null)
          $terms[] = $term;
        else
          break;
      }
    }

    if(count($terms) === 1)
      return $terms[0];

    return [
      'type' => 'logical',
      'operator' => $operator ?? 'and',
      'terms' => $terms
    ];
  }

  private function parseTerm(): ?array
  {
    $this->skipWhitespace();
    
    // Check for field name (for record mode)
    $fieldName = $this->parseIdentifier();
    $this->skipWhitespace();
    
    // Parse operators
    if($fieldName)
    {
      $operator = $this->parseOperator();
      if(!$operator)
        return null;
        
      $value = $this->parseValue();
      if($value === null)
        return null;

      return [
        'type' => 'comparison',
        'field' => $fieldName,
        'operator' => $operator,
        'value' => $value
      ];
    }
    
    // Full text mode
    $value = $this->parseValue();
    if($value === null)
      return null;

    return [
      'type' => 'text',
      'value' => $value
    ];
  }

  private function parseIdentifier(): ?string
  {
    $identifier = '';
    while($this->currentPos < strlen($this->filterString))
    {
      $char = $this->filterString[$this->currentPos];
      if(preg_match('/[a-zA-Z0-9_.]/', $char))
      {
        $identifier .= $char;
        $this->currentPos++;
      }
      else
        break;
    }
    
    return $identifier ?: null;
  }

  private function parseOperator(): ?string
  {
    $operators = ['=', '!=', '>', '<', '>=', '<=', 'in', '!in', 'contains_any', 'contains_all'];
    foreach($operators as $op)
    {
      if(substr($this->filterString, $this->currentPos, strlen($op)) === $op)
      {
        $this->currentPos += strlen($op);
        return $op;
      }
    }
    return null;
  }

  private function parseValue()
  {
    $this->skipWhitespace();
    
    if($this->currentPos >= strlen($this->filterString))
      return null;

    $char = $this->filterString[$this->currentPos];

    // String
    if($char === '"' || $char === "'")
    {
      $this->currentPos++;
      $value = '';
      while($this->currentPos < strlen($this->filterString))
      {
        if($this->filterString[$this->currentPos] === $char)
        {
          $this->currentPos++;
          return $value;
        }
        $value .= $this->filterString[$this->currentPos];
        $this->currentPos++;
      }
    }
    
    // Regex
    if($char === '/')
    {
      $this->currentPos++;
      $pattern = '';
      while($this->currentPos < strlen($this->filterString))
      {
        if($this->filterString[$this->currentPos] === '/')
        {
          $this->currentPos++;
          // Get regex flags
          $flags = '';
          while($this->currentPos < strlen($this->filterString) && 
                preg_match('/[a-z]/', $this->filterString[$this->currentPos]))
          {
            $flags .= $this->filterString[$this->currentPos];
            $this->currentPos++;
          }
          return ['type' => 'regex', 'pattern' => $pattern, 'flags' => $flags];
        }
        $pattern .= $this->filterString[$this->currentPos];
        $this->currentPos++;
      }
    }

    // Array
    if($char === '[')
    {
      $this->currentPos++;
      $values = [];
      while($this->currentPos < strlen($this->filterString))
      {
        $this->skipWhitespace();
        if($this->filterString[$this->currentPos] === ']')
        {
          $this->currentPos++;
          return $values;
        }
        if($this->filterString[$this->currentPos] === ',')
        {
          $this->currentPos++;
          continue;
        }
        $value = $this->parseValue();
        if($value !== null)
          $values[] = $value;
      }
    }

    // Numbers
    if(preg_match('/[0-9.-]/', $char))
    {
      $number = '';
      while($this->currentPos < strlen($this->filterString) && 
            preg_match('/[0-9.-]/', $this->filterString[$this->currentPos]))
      {
        $number .= $this->filterString[$this->currentPos];
        $this->currentPos++;
      }
      if(is_numeric($number))
        return floatval($number);
    }

    // Boolean and null
    $constants = ['true' => true, 'false' => false, 'null' => null];
    foreach($constants as $word => $value)
    {
      if(substr($this->filterString, $this->currentPos, strlen($word)) === $word)
      {
        $this->currentPos += strlen($word);
        return $value;
      }
    }

    return null;
  }

  private function skipWhitespace(): void
  {
    while($this->currentPos < strlen($this->filterString) && 
          preg_match('/\s/', $this->filterString[$this->currentPos]))
    {
      $this->currentPos++;
    }
  }

  public function check($input, array $tree = null): bool
  {
    if($tree === null)
      $tree = $this->tree;

    if(!is_array($tree))
      return false;

    if($tree['type'] === 'logical')
    {
      $results = array_map(function($term) use ($input) {
        return $this->check($input, $term);
      }, $tree['terms']);

      return $tree['operator'] === 'and' 
        ? !in_array(false, $results, true)
        : in_array(true, $results, true);
    }

    if($tree['type'] === 'text')
    {
      if(is_string($input))
      {
        if(is_array($tree['value']) && $tree['value']['type'] === 'regex')
          return (bool)preg_match('/' . $tree['value']['pattern'] . '/' . $tree['value']['flags'], $input);
        
        $searchValues = [$tree['value']];
        if(isset($this->synonyms[$tree['value']]))
          $searchValues = array_merge($searchValues, $this->synonyms[$tree['value']]);
          
        foreach($searchValues as $value)
        {
          if(stripos($input, $value) !== false)
            return true;
        }
      }
      return false;
    }

    if($tree['type'] === 'comparison')
    {
      $value = $this->getNestedValue($input, $tree['field']);
      
      switch($tree['operator'])
      {
        case '=':
          if(is_array($tree['value']) && $tree['value']['type'] === 'regex')
            return (bool)preg_match('/' . $tree['value']['pattern'] . '/' . $tree['value']['flags'], $value);
          return $value === $tree['value'];
        
        case '!=':
          if(is_array($tree['value']) && $tree['value']['type'] === 'regex')
            return !(bool)preg_match('/' . $tree['value']['pattern'] . '/' . $tree['value']['flags'], $value);
          return $value !== $tree['value'];
        
        case '>': return $value > $tree['value'];
        case '<': return $value < $tree['value'];
        case '>=': return $value >= $tree['value'];
        case '<=': return $value <= $tree['value'];
        
        case 'in':
          return in_array($value, $tree['value'], true);
        
        case '!in':
          return !in_array($value, $tree['value'], true);
        
        case 'contains_any':
          return count(array_intersect($value, $tree['value'])) > 0;
        
        case 'contains_all':
          return count(array_intersect($value, $tree['value'])) === count($tree['value']);
      }
    }

    return false;
  }

  private function getNestedValue($input, string $field)
  {
    $parts = explode('.', $field);
    $current = $input;
    
    foreach($parts as $part)
    {
      if(!is_array($current) || !isset($current[$part]))
        return null;
      $current = $current[$part];
    }
    
    return $current;
  }
}
