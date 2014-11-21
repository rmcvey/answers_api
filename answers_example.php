<?php
require_once 'answers_api.php';

// set api key and client IP address
answers_api::$debug = true;
#answers_api::$env = 'local';
answers_api::$key = '##YOUR_KEY##';
answers_api::$ip  = (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1');
// turn on curl debugging by switching this to true
answers_api::$debug = false;

answers_api::auth('user', 'pwd');
$ask_response = answers_api::ask("What's up with ".mt_rand(1000, 9999)."?");

print_r($ask_response);

$etag = $ask_response['headers']['ETag'];
$question_identifier = end(explode('/', $ask_response['@attributes']['href']));

$answer_response = answers_api::answer($question_identifier, "OMG great question! No clue about that though.", $etag);

print_r($answer_response);

// standard search example using default options
$search_response = answers_api::search('When does the bacon narwhal?');

print_r($search_response);

// paged and filtered search example
$page = 2;
$limit = 5;
$filters = array(
    'filters' => 'answered',
    'corpus' => 'wiki'
);
$paged_response = answers_api::search('Why is the sky blue?', $filters, $page, $limit);

print_r($paged_response);


// categorize example
$cat_response = answers_api::categorize('The bacon narwhals at midnight');

print_r($cat_response);

?>
