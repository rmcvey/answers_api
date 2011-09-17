<?php
require_once 'answers_api.php';

// set api key
answers_api::$key = 'YOUR_API_KEY';
answers_api::$ip  = $_SERVER['REMOTE_ADDR'];

// turn on curl debuggin by switching this to true
answers_api::$debug = false;

$ask_response = answers_api::ask("What's up with that?");

print_r($ask_response);
// standard search example using default options
$search_response = answers_api::search('When does the bacon narwhal?');

// paged and filtered search example
$page = 2;
$limit = 5;
$filters = array(
    'filters' => 'answered',
    'corpus' => 'wiki'
);
$paged_response = answers_api::search('Why is the sky blue?', $filters, $page, $limit);

// categorize example
$cat_response = answers_api::categorize('The bacon narwhals at midnight');

print_r($search_response);
print_r($paged_response);
print_r($cat_response);

?>