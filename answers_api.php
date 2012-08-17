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
     * 	@param env <string> Uses production servers if anything other than "local" or "staging"
     */
    public static $env = 'prod';
    /**
     *  @param authorized <bool> Whether or not user has authenticated
     */
    private static $authorized = false;
    // Answers.com hosts
    private static $standard_host;
    private static $secure_host;
    private static $user = "anonymous";

    const CATEGORIZE_PATH = '/api/category-suggest';
    const SEARCH_PATH = '/api/search';
    const AUTH_PATH = '/api/login';
    const EXTERNAL_AUTH_PATH = '/api/external-login';
    const ASK_PATH = '/api/docs/wiki';
    const ANSWER_PATH = '/api/docs/wiki/%s/answer/content';
    const DOC_PATH = '/api/docs';
    const USER_PATH = '/api/users';

    /**
     * 	Categorizes a question
     * 	@param question <string> question
     * 	@return <array> (question, category1, category2, category3) or String error message
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

        if(($result = self::parse_response($text_response)) === false){
            return "Error retrieving document";
        }

        if(array_key_exists('message', $result)){
            return $result['message'];
        }

        if(empty($result['categories'])){
            return "Unable to categorize the requested text";
        }

        $categories = $result['categories']['category'];
        $response = array();

        if (is_array($categories)) {
            foreach ($categories as $key => $category) {
                $title = $category['title'];
                $response[] = $title;
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
        self::initialize();
        $title = str_replace(' ', '_', $title);
        $url = self::$standard_host . self::DOC_PATH . "/$app/$title?content-format=text/x-answers-html";
        $headers = self::get_common_headers();

        // if the title is passed as the full path to the document, use the path
        if(strpos($title, "http://") !== false){
            $parts = parse_url($title);
            $url = self::$standard_host . $parts['path'] . '?content-format=text/x-answers-html';
        }
        
        $text_response = self::get(
            $url,
            $headers
        );

        $etag = self::get_headers($url, 'ETag');

        $response = self::parse_response($text_response);
        $response['ETag'] = $etag;

        return $response;
    }
    
    /**
     * 	Sets the API host and ensures required headers are set
     */
    private static function initialize() {
        self::check_required_headers();

        if (self::$env === 'staging') {
            self::$standard_host = 'http://en.stage.api.answers.com';
            self::$secure_host = 'https://en.stage.api.answers.com';
        } else if (self::$env = 'local') {
            self::$standard_host = 'http://ward-local.wiki.answers.com';
            self::$secure_host = 'http://ward-local.wiki.answers.com';
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
        self::$user = $uname;
        
        $auth_token = base64_encode("$uname:$pass");

        $headers = self::get_common_headers();
        $headers[] = "Authorization: Basic $auth_token";

        $response = self::get(
            self::$secure_host . self::AUTH_PATH,
            $headers
        );

        $response = self::parse_response($response);

        //if response is false or has message attribute, then it was an error
        if ($response == false || 
               (is_array($response) && array_key_exists('message', $response))) {
            self::$authorized = false;
        }else{
            self::$authorized = true;
        }

        return self::$authorized;
    }

    /**
     *   Authenticate against the Answers API with Facebook login
     *   Creates Answers user from Facebook info if hasn't already been created
     *   @param fb_user <associative array, keys: first_name, last_name, email, id> 
     *      Facebook User
     *   @return boolean true on success, false on failure
     */
    public static function auth_with_facebook($fb_user){
        self::initialize();

        // bail if already authorized with Answers creds
        if (self::$authorized){
           return false;
        }

        $doctmpl =
           "<?xml version='1.0'?>
           <user>
            <name>%s</name>
            <email>%s</email>
            <facebookuser>%s</facebookuser>
            <network>facebook</network>
            <first-name>%s</first-name>
            <last-name>%s</last-name>
            <nickname></nickname>
            <profile-url></profile-url>
            <photo-url></photo-url>
          </user>";

        $doc = sprintf($doctmpl,
                  sprintf("%s %s", $fb_user['first_name'], $fb_user['last_name']),
                  $fb_user['email'],
                  $fb_user['id'],
                  $fb_user['first_name'],
                  $fb_user['last_name']);

        $headers = self::get_common_headers();

        $response = self::put(
            self::$secure_host . self::EXTERNAL_AUTH_PATH,
            $doc,
            $headers
        );

        $response = self::parse_response($response);

        //if response is false or has message attribute, then it was an error
        if ($response == false || 
               (is_array($response) && array_key_exists('message', $response))) {
            self::$authorized = false;
        }else{
            self::$authorized = true;
        }

        return self::$authorized;
    }

    public static function get_api_doc($url)
    {
        $headers = self::get_common_headers();

        $text_response = self::get(
            $url,
            $headers
        );

        $response = self::parse_response($text_response);

        return $response;
    }

    /**
     * Get info on user
     * @param username <string> Answers.com user name
     */
    public static function user_info($username)
    {
        self::initialize();

        $headers = self::get_common_headers();

        $document = self::get(
            self::$standard_host . self::USER_PATH . "/" . str_replace(' ', '_', $username),
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
     * 	Ask a question (not fully functional)
     *
     * 	@param question <string> If you have to ask
     * 	@return error message or array response
     */
    public static function ask($question) {
        if(self::$authorized == false){
            throw new Exception("Auth required prior to calling ask");
        }
        self::initialize();

        $headers = self::get_common_headers();

        $data = "<doc><title>$question</title></doc>";
        $document = self::post(
            self::$standard_host . self::ASK_PATH,
            $data,
            $headers,
            true
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
     *  @param etag <string> document etag
     * 	@return error message or array response
     */
    public static function answer($question_title, $answer, $etag=NULL) {
        if(self::$authorized == false){
            throw new Exception("Auth required prior to calling answer");
        }
        if(is_null($id)){
            throw new Exception("ID of document required");
        }

        $answer = sprintf(
            '<content href="%s">
                <![CDATA[%s]]>
             </content>',
            $question_url,
            $answer
        );
        $headers = self::get_common_headers();
        $headers[]= "If-Match: $etag";
        
        $response = self::put(
            self::$standard_host . sprintf(self::ANSWER_PATH, $question_url),
            $answer,
            $headers
        );
        
        $response = self::parse_response($response);

        if (array_key_exists('message', $response)) {
            return sprintf("Could not create answer: %s", $response['message']);
        } else {
            return $response;
        }
    }

    /**
     * 	Delete answers.com document
     * 	@param id <string> ID of Document
     * 	@param url <string> API URL of document
     * 	@return true on success, message on error
     */
    public static function delete($id, $url) {
        if(self::$authorized == false){
            throw new Exception("Auth required prior to calling ask");
        }
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
        if (!$xml) {
            return false;
        }

        if(strpos($xml, '<?xml') !== 0) {
            list($headers, $xml) = explode("<?xml", $xml);
            $parsed_headers = self::parse_response_headers($headers);
            $xml = '<?xml'.$xml;
        }

        libxml_use_internal_errors(true);
        $xml_object = simplexml_load_string($xml, null, LIBXML_NOCDATA);
        if (is_object($xml_object)) {
            $parsed = json_decode(json_encode($xml_object),true);
        } else {
            foreach (libxml_get_errors() as $err) {
                error_log(__METHOD__ . ": XML error: {$err->message}");
            }
            libxml_clear_errors();
        }

        if(!empty($parsed)) {
            if(!empty($parsed_headers)) $parsed['headers'] = $parsed_headers;
            return $parsed;
        }

        return false;
    }

    /**
     * Parse return HTTP headers into an array
     * @param headers <string> HTTP Response Headers from API
     * @return array
     */
    private static function parse_response_headers($headers)
    {
        if (!$headers) {
            return false;
        }

        $return = array();
        $rows = explode("\n", $headers);
        foreach($rows as $row) {
            list($key, $value) = explode(':', $row, 2);
            if(!empty($value)) {
                $return[trim($key)] = trim($value);
            }
        }

        if(!empty($return)) return $return;

        return false;
    }

    /**
     * 	Perform a DELETE request
     */
    private static function remove($url, $headers = array(), $return_headers = false) {
        return self::rest('DELETE', $url, NULL, $headers, $return_headers);
    }

    /**
     * 	Perform GET request
     */
    private static function get($url, $headers = array(), $return_headers = false) {
        return self::rest('GET', $url, NULL, $headers, $return_headers);
    }

    /**
     * 	Perform PUT request
     */
    private static function put($url, $vars, $headers = array(), $return_headers = false) {
        return self::rest('PUT', $url, $vars, $headers, $return_headers);
    }

    /**
     * 	Perform POST request
     */
    private static function post($url, $vars, $headers = array(), $return_headers = false) {
        return self::rest('POST', $url, $vars, $headers, $return_headers);
    }

    private static function get_headers($url, $search_key = NULL) {
        $headers = array();
        $url = parse_url($url);
        $host = isset($url['host']) ? $url['host'] : '';
        $port = isset($url['port']) ? $url['port'] : 80;
        $path = (isset($url['path']) ? $url['path'] : '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        $fp = fsockopen($host, $port, $errno, $errstr, 3);
        if ($fp)
        {
            $hdr = "GET $path HTTP/1.1\r\n";
            $hdr .= "Host: $host \r\n";
            $hdr .= "X-Answers-apikey:" . self::$key . "\r\n";
            $hdr .= "X-Answers-user-ip: " . self::$ip ." \r\n";
            $hdr .= "Connection: Close\r\n\r\n";
            fwrite($fp, $hdr);
            while (!feof($fp) && $line = trim(fgets($fp, 1024)))
            {
                if ($line == "\r\n") break;
                list($key, $val) = explode(': ', $line, 2);
                if ($val) {
                    $headers[$key] = $val;
                }
                else {
                    $headers[] = $key;
                }
            }
            fclose($fp);
            if(!is_null($search_key)){
                if(array_key_exists($search_key, $headers)){
                    return $headers[$search_key];
                }
                return NULL;
            }
            return $headers;
        }
        return false;
    }

    // curl stuff
    private static function rest($method, $url, $vars, $headers, $return_headers = false) {
        $ch = curl_init($url);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $cookie_jar = sprintf('/tmp/cookie_%s_%s.txt',
            base64_encode(self::$user),
            date('Ymd', strtotime('Now'))
        );

        curl_setopt_array($ch, array(
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; U; Intel Mac OS X; en-US; rv:1.8.1.7) Gecko/20070914 Firefox/2.0.0.7",
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_COOKIEJAR => $cookie_jar,
            CURLOPT_COOKIEFILE => $cookie_jar,
            CURLOPT_NOBODY => "true"
        ));
        
        if($return_headers === true) {
            curl_setopt($ch, CURLOPT_HEADER, 1);
        }

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
                $putData = tmpfile();
                fwrite($putData, $vars);
                fseek($putData, 0);
                curl_setopt($ch, CURLOPT_PUT, true);
                curl_setopt($ch, CURLOPT_INFILE, $putData);
                curl_setopt($ch, CURLOPT_INFILESIZE, strlen($vars));
                #curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                break;
            default:
                break;
        }

        $data = curl_exec($ch);

        if (empty($data)) {
            $data = curl_error($ch);
        }

        curl_close($ch);
        return $data;
    }

}

