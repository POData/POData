<?php

namespace POData\OperationContext\Web;

use POData\Common\ODataException;
use POData\Common\ODataConstants;
use POData\Common\Url;
use POData\HttpProcessUtility;
use POData\OperationContext\HTTPRequestMethod;
use POData\OperationContext\IHTTPRequest;

/**
 * Class IncomingRequest
 * Class represents HTTP methods,headers and stream associated with a HTTP request
 * Note: This class will not throw any error
 * @package POData\OperationContext\Web
 */
class IncomingRequest implements IHTTPRequest
{
    /**
     * The request headers
     * 
     * @var array
     */
    private $_headers;
    
    /**
     * The incoming url in raw format
     * 
     * @var string
     */
    private $_rawUrl  = null;
    

    /**
     * The request method (GET, POST, PUT, DELETE or MERGE)
     * 
     * @var HTTPRequestMethod HttpVerb
     */
    private $_method;

    /**
     * The query options as key value.
     * 
     * @var array(string, string);
     */
    private $_queryOptions;

    /**
     * A collection that represents mapping between query 
     * option and its count.
     * 
     * @var array(string, int)
     */
    private $_queryOptionsCount;

    /**
     * Initialize a new instance of IncomingWebRequestContext
     */
    public function __construct()
    {
        $this->_method = new HTTPRequestMethod($_SERVER['REQUEST_METHOD']);
        $this->_queryOptions = null;
        $this->_queryOptionsCount = null;
        $this->_headers = null;
        $this->getHeaders();
    }

    /**
     * Get the request headers
     * By-default we will get the following headers:
     * HTTP_HOST
     * HTTP_USER_AGENT
     * HTTP_ACCEPT
     * HTTP_ACCEPT_LANGUAGE
     * HTTP_ACCEPT_ENCODING
     * HTTP_ACCEPT_CHARSET
     * HTTP_KEEP_ALIVE
     * HTTP_CONNECTION,
     * HTTP_CACHE_CONTROL
     * HTTP_USER_AGENT
     * HTTP_IF_MATCH
     * HTTP_IF_NONE_MATCH
     * HTTP_IF_MODIFIED
     * HTTP_IF_MATCH
     * HTTP_IF_NONE_MATCH
     * HTTP_IF_UNMODIFIED_SINCE
     * //TODO: these aren't really headers...
     * REQUEST_URI
     * REQUEST_METHOD
     * REQUEST_TIME
     * SERVER_NAME
     * SERVER_PORT
     * SERVER_PORT_SECURE
     * SERVER_PROTOCOL
     * SERVER_SOFTWARE
     * CONTENT_TYPE
     * CONTENT_LENGTH
     * We may get user defined customized headers also like
     * HTTP_DATASERVICEVERSION, HTTP_MAXDATASERVICEVERSION
     * 
     * @return string[]
     */
    private function getHeaders()
    {
        if (is_null($this->_headers)) {
            $this->_headers = array();

            foreach ($_SERVER as $key => $value) {
                if ((strpos($key, 'HTTP_') === 0) 
                    || (strpos($key, 'REQUEST_') === 0)
                    || (strpos($key, 'SERVER_') === 0) 
                    || (strpos($key, 'CONTENT_') === 0)
                ) {
                    $trimmedValue = trim($value);
                    $this->_headers[$key] = isset($trimmedValue) ? $trimmedValue : null;
                }
            }

        }

        return $this->_headers;
    }

    /**
     * get the raw incoming url
     * 
     * @return string RequestURI called by User with the value of QueryString
     */  
    public function getRawUrl()
    {
        if (is_null($this->_rawUrl)) {
            if (!preg_match('/^HTTPS/', $_SERVER[ODataConstants::HTTPREQUEST_PROTOCOL])) {
                $this->_rawUrl = ODataConstants::HTTPREQUEST_PROTOCOL_HTTP;
            } else {
                $this->_rawUrl = ODataConstants::HTTPREQUEST_PROTOCOL_HTTPS;
            }

            $this->_rawUrl .= "://".$_SERVER[HttpProcessUtility::headerToServerKey(ODataConstants::HTTPREQUEST_HEADER_HOST)];
            $this->_rawUrl .= utf8_decode(urldecode($_SERVER[ODataConstants::HTTPREQUEST_URI]));
        }

        return $this->_rawUrl;
    }

    /**
     * get the specific request headers
     * 
     * @param string $key The header name
     * 
     * @return string|null value of the header, NULL if header is absent.
     */
    public function getRequestHeader($key)
    {
	    //PHP normalizes header keys
	    $trimmedKey = HttpProcessUtility::headerToServerKey(trim($key));

	    if (array_key_exists($trimmedKey, $this->_headers)) {
            return $this->_headers[$trimmedKey];
        }

        return null;
    }

    /**
     * Get the QUERY_STRING
     * Note: This method will return empty string if no query string present.
     * 
     * @return string $_header[HttpRequestHeaderQueryString]
     */
    private function getQueryString()
    {
        if (array_key_exists(ODataConstants::HTTPREQUEST_QUERY_STRING, $_SERVER)) {
            return utf8_decode(trim($_SERVER[ODataConstants::HTTPREQUEST_QUERY_STRING]));
        } else {
            return "";
        }
    }
    
    /**
     * Split the QueryString and assigns them as array element in KEY=VALUE
     * 
     * @return string[]
     */
    public function getQueryParameters()
    {
        if (is_null($this->_queryOptions)) {
            $queryString = $this->getQueryString();
            $this->_queryOptions = array();

            foreach (explode('&', $queryString) as $queryOptionAsString) {
                $queryOptionAsString = trim($queryOptionAsString);
                if (!empty($queryOptionAsString)) {    
                    $result = explode('=', $queryOptionAsString, 2);
                    $isNamedOptions = count($result) == 2;
                    if ($isNamedOptions) {
                        $this->_queryOptions[]
                            = array (rawurldecode($result[0]) => trim(rawurldecode($result[1])));
                    } else {
                        $this->_queryOptions[]
                            = array(null => trim(rawurldecode($result[0])));
                    }
                }
            }
        }

        return $this->_queryOptions;
    }


    
    /**
     * Get the HTTP method
     * Value will be set from the value of the HTTP method of the 
     * incoming Web request.
     * 
     * @return HTTPRequestMethod $_header[HttpRequestHeaderMethod]
     */
    public function getMethod()
    {
        return $this->_method;
    }


}
