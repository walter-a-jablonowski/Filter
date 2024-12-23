<?php

require_once 'vendor/autoload.php';
require_once '../Filter.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if(!isset($input['mode']) || !isset($input['filter']))
{
  http_response_code(400);
  echo json_encode(['error' => 'Invalid request']);
  exit;
}

try
{
  $filter = new Filter('synonyms.yml');
  $tree = $filter->parse($input['filter']);

  if($input['mode'] === 'text')
  {
    if(!isset($input['input']))
    {
      http_response_code(400);
      echo json_encode(['error' => 'No input text provided']);
      exit;
    }

    echo json_encode([
      'result' => $filter->check($input['input'], $tree)
    ]);
  }
  else if($input['mode'] === 'records')
  {
    if(!isset($input['records']) || !is_array($input['records']))
    {
      http_response_code(400);
      echo json_encode(['error' => 'No records provided']);
      exit;
    }

    $results = [];
    foreach($input['records'] as $record)
    {
      $record['result'] = $filter->check($record, $tree);
      $results[] = $record;
    }

    echo json_encode(['results' => $results]);
  }
  else
  {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode']);
  }
}
catch(Exception $e)
{
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
