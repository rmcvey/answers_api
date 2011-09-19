<?php

/**
 * 	Answers API facade
 * 	@author Rob McVey
 */
class answers_api {
    /**
     * 	@param key <string> API key
     */
    public static $key;
    /**
     * 	@param ip <string> Client IP Address
     */
    public static $ip;
    /**
     * 	@param debug <bool> Switches curl debugging on (true) or off (false)
     */
    public static $debug = false;
    /**
     * 	@param staging <bool> Uses production servers if false
     */
    public static $staging = true;
    // Answers.com hosts
    private static $standard_host;
    private static $secure_host;

    const CATEGORIZE_PATH = '/api/category-suggest';
    const SEARCH_PATH = '/api/search';
    const AUTH_PATH = '/api/login';
    const ASK_PATH = '/api/docs/wiki';
    const ANSWER_PATH = '/api/docs/wiki';
    const DOC_PATH = '/api/docs';

    /**
     * 	Categorizes a question
     * 	@param question String question
     * 	@return Array (question, category1, category2, category3, message), message is non-empty on error
     */
    public static function categorize($question) {
        self::initialize();

        $params = array(
            'q' => $question,
        );

        $headers = self::get_common_headers();

        $text_response = self::get(
            sprintf("%s?%s", self::$standard_host . self::CATEGORIZE_PATH, http_build_query($params)),
            $headers
        );

        $result = self::parse_response($text_response);

        $cat_count = 1;
        $response = array(
            'question' => $params['q'],
            'category1' => "",
            'category2' => "",
            'category3' => "",
            'message' => ''
        );

        $categories = $result['categories']['category'];

        if (is_array($categories)) {
            foreach ($categories as $key => $category) {
                $title = $category['title'];
                $response["category$cat_count"] = $title;
                $cat_count++;
                if ($cat_count >= 4) {
                    continue;
                }
            }

            return $response;
        }
        return $response;
    }

    /**
     * 	Perform search against Answers API
     * 	@param string query <string> Search term
     * 	@param option_params <hash_table>
     *   	- corpus: ref, wiki, or all, default is all
     *   	- filters: answered, unanswered, or all, default is all
     *   	- category: Category filter
     *   	- prefer-categories: Prefer certain categories
     *   	- scope: Determines what part of the document will be searched; may be full or title, default is full
     *   	- relevance: Only get results of a certain relevance level, default is comprehensive
     * 	@param page  <int default=0> The current page of results
     * 	@param limit <int default=10> The number of results requested
     * 	@return mixed String message or array with search results:
     * 	Example:
     * 	<pre>
     * 	array(
     * 		[0] => array(
     * 			title => string
     * 			attributes => array(
     * 				corpus => string
     * 				href => string
     * 				web-href => string
     * 				redirect-href => string
     * 				answered => boolean
     * 				relevance => string
     * 			)
     * 			categories => array(
     * 				category => array(
     * 					attributes => array(
     * 						href => string
     * 						corpus => string
     * 					)
     * 					title => string
     * 				)
     * 			)
     * 		)
     * 	)
     * 	</pre>
     */
    public static function search($query, $optional_params = array(), $page = 0, $limit = 10) {
        self::initialize();

        //build required headers and parameters
        $headers = self::get_common_headers();
        $params = array(
            'q' => $query,
            'start' => ($page > 0) ? ($limit * $page) : 0,
            'count' => $limit
        );

        if (!empty($optional_params)) {
            $params = array_merge($params, $optional_params);
        }

        //perform api request
        $text_response = self::get(
            sprintf("%s?%s", self::$standard_host . self::SEARCH_PATH, http_build_query($params)),
            $headers
        );

        $response = self::parse_response($text_response);

        if ($response == false) {
            return "Error retrieving document";
        }

        //'message' attribute is only present when error occurs
        if (array_key_exists('message', $response)) {
            return $response['message'];
        }

        $result_list = $response['results']['result'];

        $response = array();

        if (is_array($result_list) && count($result_list) > 0) {
            foreach ($result_list as $key => $value) {
                $cats       = $result_list[$key]['categories'];
                $attributes = $result_list[$key]['@attributes'];
                $title      = $result_list[$key]['title'];

                if (!empty($title)) {
                    if (count($cats) > 0) {
                        foreach ($cats as $key => $val) {
                            $cats[$key] = array(
                                'attributes' => $cats[$key]['@attributes'],
                                'title' => $cats[$key]['title']
                            );
                        }
                    }

                    if (empty($attributes)) {
                        $attributes = array();
                    }

                    array_push($response, array(
                        'attributes' => $attributes,
                        'title' => $title,
                        'categories' => $cats
                    ));
                }
            }
        } else {
            return "No results found for $query";
        }

        return $response;
    }

    /**
     * Retrieve a document
     * @param title <string> URL to document
     * @param app <string> ref or wiki (default)
     * @return Array
     */
    public static function document($title, $app='wiki')
    {
        $title = str_replace(' ', '_', $title);
        $url = self::$standard_host . self::DOC_PATH . "/$app/$title?content-format=text/x-answers-html";
        $headers = self::get_common_headers();

        $text_response = self::get(
            $url,
            $headers
        );

        $response = self::parse_response($text_response);

        return $response;
    }
    
    /**
     * 	Sets the API host and ensures required headers are set
     */
    private static function initialize() {
        self::check_required_headers();

        if (self::$staging === true) {
            self::$standard_host = 'http://en.stage.api.answers.com';
            self::$secure_host = 'https://en.stage.api.answers.com';
        } else {
            self::$standard_host = 'http://en.api.answers.com';
            self::$secure_host = 'https://en.api.answers.com';
        }
    }

    /**
     * 	Ensures that necessary attributes are set before making any API requests
     * 	@throws Exception on required attributes not being set
     */
    private static function check_required_headers() {
        if (empty(self::$ip) || empty(self::$key)) {
            throw new Exception("Client IP and API Key are not set");
        }
    }

    /**
     * 	Authenticate against the Answers API
     * 	@param uname <string> Username
     * 	@param pass <string> Password
     * 	@return boolean true on success, false on failure
     */
    public static function auth($uname, $pass) {
        self::initialize();

        $auth_token = base64_encode("$uname:$pass");

        $headers = self::get_common_headers();
        $headers[] = "Authorization: Basic $auth_token";

        $response = self::get(
            self::$secure_host . self::AUTH_PATH,
            $headers
        );

        $response = self::parse_response($response);

        if($response == false){
            return false;
        }
        //if response has message attribute, then it was an error
        if (array_key_exists('message', $response)) {
            return false;
        }
        return true;
    }

    /**
     * 	Ask a question (not fully functional)
     *
     * 	@param question <string> If you have to ask
     * 	@return error message or array response
     */
    public static function ask($question) {
        self::initialize();

        $headers = self::get_common_headers();

        $data = "<doc><title>$question</title></doc>";
        $document = self::post(
            self::$standard_host . self::ASK_PATH,
            $data,
            $headers
        );

        $response = self::parse_response($document);

        if ($response == false) {
            return "Empty response from API";
        }

        if (array_key_exists('message', $response)) {
            return sprintf("Could not create question: %s", $response['message']);
        } else {
            return $response;
        }
    }

    /**
     * 	Answer a question (not fully functional)
     *
     * 	@param question_url <string> URL of document
     * 	@param answer <string> Text answer to question
     *  @param id <string> document id
     * 	@return error message or array response
     */
    public static function answer($question_url, $answer, $id) {
        $answer = sprintf(
            '<content href="%s/answer/content"><![CDATA[%s]]></content>',
            $question_url,
            $answer
        );
        $headers = self::get_common_headers();
        $headers[]= "If-Match: $id";
        
        $response = self::put(
            sprintf("%s/answer/content", $question_url),
            $answer,
            $headers
        );

        $response = self::parse_response($response);

        if (array_key_exists('message', $response)) {
            return sprintf("Could not create question: %s", $response['message']);
        } else {
            return $response;
        }
    }

    /**
     * 	Delete answers.com document
     * 	@param id <string> ID of Document
     * 	@param url <string> API URL of document
     * 	@param true on success, message on error
     */
    public static function delete($id, $url) {
        if (empty($id) || empty($url)) {
            throw new Exception("ID and URL are required to delete a document");
        }
        $headers = array(
            "If-Match: $id",
            "X-Answers-apikey:" . self::$key
        );

        $response = self::remove($url, $headers);

        if (strstr($response, "<OK/>")) {
            return true;
        } else {
            return $response;
        }
    }

    /**
     * 	Returns commonly used headers
     */
    private static function get_common_headers() {
        $headers = array(
            "X-Answers-apikey:" . self::$key,
            "X-Answers-user-ip:" . self::$ip
        );
        return $headers;
    }

    /**
     * Parse return XML into JSON object
     * @param xml <string> XML Response from API
     * @return array
     */
    private static function parse_response($xml)
    {
        $xml_object = @simplexml_load_string($xml);
        return json_decode(
            json_encode($xml_object, LIBXML_NOCDATA),
            true
        );
    }

    /**
     * 	Perform a DELETE request
     */
    private static function remove($url, $headers) {
        return self::rest('DELETE', $url, NULL, $headers);
    }

    /**
     * 	Perform GET request
     */
    private static function get($url, $headers = array()) {
        return self::rest('GET', $url, NULL, $headers);
    }

    /**
     * 	Perform PUT request
     */
    private static function put($url, $vars, $headers = array()) {
        return self::rest('PUT', $url, $vars, $headers);
    }

    /**
     * 	Perform POST request
     */
    private static function post($url, $vars, $headers = array()) {
        return self::rest('POST', $url, $vars, $headers);
    }

    // curl stuff
    private static function rest($method, $url, $vars, $headers) {
        $ch = curl_init($url);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt_array($ch, array(
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; U; Intel Mac OS X; en-US; rv:1.8.1.7) Gecko/20070914 Firefox/2.0.0.7",
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_COOKIEJAR => '/tmp/cookie.txt',
            CURLOPT_COOKIEFILE => '/tmp/cookie.txt'
        ));

        if (self::$debug === true) {
            curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
            
            if(!empty($vars)){
              echo $vars;
            }
        }

        switch ($method) {
            case 'POST':
                curl_setopt_array($ch, array(
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => $vars
                ));
                break;
            case 'DELETE':
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($vars)) {
                    curl_setopt_array($ch, array(
                        CURLOPT_POSTFIELDS => $vars
                    ));
                }
                break;
            default:
                break;
        }

        $data = curl_exec($ch);

        if ($data) {
            return $data;
        }

        $error = curl_error($ch);
        curl_close($ch);
        return $error;
    }

}

