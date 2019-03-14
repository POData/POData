<?php

namespace POData\OperationContext;

use POData\Common\Messages;
use POData\Common\HttpStatus;
use POData\Common\ODataConstants;
use POData\Common\Url;
use POData\Common\UrlFormatException;
use POData\Common\ODataException;
use POData\Common\InvalidOperationException;
use POData\OperationContext\Web\WebOperationContext;
use POData\OperationContext\IOperationContext;
use POData\Common\Version;
use POData\Common\MimeTypes;

/**
 * Class ServiceHost
 *
 * It uses an IOperationContext implementation to get/set all context related
 * headers/stream info It also validates the each header value
 *
 * @package POData\OperationContext
 */
Class ServiceHost
{
    /**
     * Holds reference to the underlying operation context.
     * 
     * @var IOperationContext
     */
    private $_operationContext;

    /**
     * The absolute request Uri as Url instance.
     * Note: This will not contain query string
     * 
     * @var Url
     */
    private $_absoluteRequestUri;

    /**
     * The absolute request Uri as string
     * Note: This will not contain query string
     * 
     * @var string
     */
    private $_absoluteRequestUriAsString = null;

    /**
     * The absolute service uri as Url instance.
     * Note: This value will be taken from configuration file
     * 
     * @var Url
     */
    private $_absoluteServiceUri;

    /**
     * The absolute service uri string.
     * Note: This value will be taken from configuration file
     * 
     * @var string
     */
    private $_absoluteServiceUriAsString = null;

    /**
     * array of query-string parameters
     * 
     * @var array(string, string)
     */
    private $_queryOptions;

    /**
     * Gets reference to the operation context.
     * 
     * @return IOperationContext
     */
    public function getOperationContext()
    {
        return $this->_operationContext;
    }

	/**
	 * @param IOperationContext $context the OperationContext implementation to use.  If null the WebOperationContext
	 * will be used.  Defaults to null.
	 */
	public function __construct(IOperationContext $context = null)
    {
        if(is_null($context)){
	        $this->_operationContext = new WebOperationContext();
        } else {
	        $this->_operationContext = $context;
        }

        // getAbsoluteRequestUri can throw UrlFormatException 
        // let Dispatcher handle it
        $this->_absoluteRequestUri = $this->getAbsoluteRequestUri();
        $this->_absoluteServiceUri = null;
    }

    /**
      * Gets the absolute request Uri as Url instance
      * Note: This method will be called first time from constructor.
      * 
      * @throws ODataException if AbsoluteRequestUri is not a valid URI
      * 
      * @return Url
      */
    public function getAbsoluteRequestUri()
    {
        if (is_null($this->_absoluteRequestUri)) {
            $this->_absoluteRequestUriAsString = $this->_operationContext->incomingRequest()->getRawUrl();
            // Validate the uri first
            try {
                new Url($this->_absoluteRequestUriAsString);
            } catch (UrlFormatException $exception) {
				throw ODataException::createBadRequestError($exception->getMessage());
            }

            $queryStartIndex = strpos($this->_absoluteRequestUriAsString, '?');
            if ($queryStartIndex !== false) {
                $this->_absoluteRequestUriAsString = substr(
                    $this->_absoluteRequestUriAsString,
                    0,
                    $queryStartIndex
                );
            }

            // We need the absolute uri only not associated components 
            // (query, fragments etc..)
            $this->_absoluteRequestUri = new Url($this->_absoluteRequestUriAsString);
            $this->_absoluteRequestUriAsString = rtrim($this->_absoluteRequestUriAsString, '/');
        }

        return $this->_absoluteRequestUri;
    }

    /**
     * Gets the absolute request Uri as string
     * Note: This will not contain query string
     * 
     * @return string
     */
    public function getAbsoluteRequestUriAsString()
    {
        return $this->_absoluteRequestUriAsString;
    }


    /**
     * Sets the service url from which the OData URL is parsed
     *
     * @param string $serviceUri The service url, absolute or relative.
     * 
     * @return void
     * 
     * @throws ODataException If the base uri in the configuration is malformed.
     */
    public function setServiceUri($serviceUri)
    {
        if (is_null($this->_absoluteServiceUri)) {
            $isAbsoluteServiceUri = (strpos($serviceUri, 'http://') === 0)
                || (strpos($serviceUri, 'https://') === 0);
            try {
                $this->_absoluteServiceUri = new Url($serviceUri, $isAbsoluteServiceUri );
            } catch (UrlFormatException $exception) {
                throw ODataException::createInternalServerError(Messages::hostMalFormedBaseUriInConfig());
            }

            $segments = $this->_absoluteServiceUri->getSegments();
            $lastSegment = $segments[count($segments) - 1];
            $endsWithSvc 
                = (substr_compare($lastSegment, '.svc', -strlen('.svc'), strlen('.svc')) === 0);
            if (!$endsWithSvc 
                || !is_null($this->_absoluteServiceUri->getQuery()) 
                || !is_null($this->_absoluteServiceUri->getFragment())
            ) {
                throw ODataException::createInternalServerError(Messages::hostMalFormedBaseUriInConfig(true));
            }

            if (!$isAbsoluteServiceUri) {
                $requestUriSegments = $this->_absoluteRequestUri->getSegments();
                $i = count($requestUriSegments) - 1;
                // Find index of segment in the request uri that end with .svc
                // There will be always a .svc segment in the request uri otherwise
                // uri redirection will not happen.
                for (; $i >=0; $i--) {
                    $endsWithSvc = (substr_compare($requestUriSegments[$i], '.svc', -strlen('.svc'), strlen('.svc')) === 0);
                    if ($endsWithSvc) {
                        break;
                    }
                }
                
                $j = count($segments) - 1;
                $k = $i;
                if ($j > $i) {
					throw ODataException::createBadRequestError(
                        Messages::hostRequestUriIsNotBasedOnRelativeUriInConfig(
                            $this->_absoluteRequestUriAsString,
                            $serviceUri
                        )
                    );
                }

                while ($j >= 0 && ($requestUriSegments[$i] === $segments[$j])) {
                    $i--; $j--;
                }

                if ($j != -1) {
					throw ODataException::createBadRequestError(
                        Messages::hostRequestUriIsNotBasedOnRelativeUriInConfig(
                            $this->_absoluteRequestUriAsString,
                            $serviceUri
                        )
                    );
                }

                $serviceUri = $this->_absoluteRequestUri->getScheme() 
                    . '://' 
                    . $this->_absoluteRequestUri->getHost()
                    . ':' 
                    . $this->_absoluteRequestUri->getPort();

                for ($l = 0; $l <= $k; $l++) {
                    $serviceUri .= '/' . $requestUriSegments[$l];
                }
                
                $this->_absoluteServiceUri = new Url($serviceUri);
            }

            $this->_absoluteServiceUriAsString = $serviceUri;
        }
    }


    /**
     * Gets the absolute Uri to the service as Url instance.
     * Note: This will be the value taken from configuration file.
     * 
     * @return Url
     */
    public function getAbsoluteServiceUri()
    {
        return $this->_absoluteServiceUri;
    }

    /**
     * Gets the absolute Uri to the service as string
     * Note: This will be the value taken from configuration file.
     * 
     * @return string
     */
    public function getAbsoluteServiceUriAsString()
    {
        return $this->_absoluteServiceUriAsString;
    }


    /**
     * This method verfies the client provided url query parameters and check whether
     * any of the odata query option specified more than once or check any of the 
     * non-odata query parameter start will $ symbol or check any of the odata query 
     * option specified with out value. If any of the above check fails throws 
     * ODataException, else set _queryOptions member variable
     * 
     * @return void
     * 
     * @throws ODataException
     */
    public function validateQueryParameters()
    {
        $queryOptions = $this->_operationContext->incomingRequest()->getQueryParameters();

        reset($queryOptions);
        $namesFound = array();
        while ($queryOption = current($queryOptions)) {
            $optionName = key($queryOption);
            $optionValue = current($queryOption);
            if (empty($optionName)) {
                if (!empty($optionValue)) {
                    if ($optionValue[0] == '$') {
                        if ($this->_isODataQueryOption($optionValue)) {
							throw ODataException::createBadRequestError(
                                Messages::hostODataQueryOptionFoundWithoutValue(
                                    $optionValue
                                )
                            );
                        } else {
							throw ODataException::createBadRequestError(
                                Messages::hostNonODataOptionBeginsWithSystemCharacter(
                                    $optionValue
                                )
                            );
                        }
                    }
                }
            } else {
                if ($optionName[0] == '$') {
                    if (!$this->_isODataQueryOption($optionName)) {
						throw ODataException::createBadRequestError(
                            Messages::hostNonODataOptionBeginsWithSystemCharacter(
                                $optionName
                            )
                        );
                    }

                    if (array_search($optionName, $namesFound) !== false) {
						throw ODataException::createBadRequestError(
                            Messages::hostODataQueryOptionCannotBeSpecifiedMoreThanOnce(
                                $optionName
                            )
                        );
                    }
                    
                    if (empty($optionValue) && $optionValue !== '0') {
						throw ODataException::createBadRequestError(
                            Messages::hostODataQueryOptionFoundWithoutValue(
                                $optionName
                            )
                        );
                    }

                    $namesFound[] = $optionName;
                }
            }
            
            next($queryOptions);
        }
        
        $this->_queryOptions = $queryOptions;
    }
    
    /**
     * Verifies the given url option is a valid odata query option.
     * 
     * @param string $optionName option to validate
     * 
     * @return boolean True if the given option is a valid odata option False otherwise.
     *
     */
    private function _isODataQueryOption($optionName)
    {
        return ($optionName === ODataConstants::HTTPQUERY_STRING_FILTER ||
                $optionName === ODataConstants::HTTPQUERY_STRING_EXPAND ||
                $optionName === ODataConstants::HTTPQUERY_STRING_INLINECOUNT ||
                $optionName === ODataConstants::HTTPQUERY_STRING_ORDERBY ||
                $optionName === ODataConstants::HTTPQUERY_STRING_SELECT ||
                $optionName === ODataConstants::HTTPQUERY_STRING_SKIP ||
                $optionName === ODataConstants::HTTPQUERY_STRING_SKIPTOKEN ||
                $optionName === ODataConstants::HTTPQUERY_STRING_TOP ||
                $optionName === ODataConstants::HTTPQUERY_STRING_FORMAT);
    }

    /**
     * Gets the value for the specified item in the request query string
     * Remark: This method assumes 'validateQueryParameters' has already been 
     * called.
     * 
     * @param string $item The query item to get the value of.
     * 
     * @return string|null The value for the specified item in the request 
     *                     query string NULL if the query option is absent.
     */
    public function getQueryStringItem($item)
    {
        foreach ($this->_queryOptions as $queryOption) {
            if (array_key_exists($item, $queryOption)) {
                return $queryOption[$item];
            }
        }

        return null;
    }

    /**
     * Gets the value for the DataServiceVersion header of the request.
     * 
     * @return string|null
     */
    public function getRequestVersion()
    {
        return $this->_operationContext
            ->incomingRequest()
            ->getRequestHeader(ODataConstants::HTTPREQUEST_HEADER_DATA_SERVICE_VERSION);
    }

    /**
     * Gets the value of MaxDataServiceVersion header of the request
     * 
     * @return string|null
     */
    public function getRequestMaxVersion()
    {
        return $this->_operationContext
            ->incomingRequest()
            ->getRequestHeader(ODataConstants::HTTPREQUEST_HEADER_MAX_DATA_SERVICE_VERSION);
    }     

    
    /**
     * Get comma separated list of client-supported MIME Accept types
     * 
     * @return string
     */
    public function getRequestAccept()
    {
        return $this->_operationContext
            ->incomingRequest()
            ->getRequestHeader(ODataConstants::HTTPREQUEST_HEADER_ACCEPT);
    }


    /**
     * Get the character set encoding that the client requested
     * 
     * @return string
     */
    public function getRequestAcceptCharSet()
    {
        return $this->_operationContext
            ->incomingRequest()
            ->getRequestHeader(ODataConstants::HTTPREQUEST_HEADER_ACCEPT_CHARSET);
    }
        


    /**
     * Get the value of If-Match header of the request
     * 
     * @return string 
     */
    public function getRequestIfMatch()
    {
        return $this->_operationContext
            ->incomingRequest()
            ->getRequestHeader(ODataConstants::HTTPREQUEST_HEADER_IF_MATCH);
    }
        
    /**
     * Gets the value of If-None-Match header of the request
     * 
     * @return string
     */
    public function getRequestIfNoneMatch()
    {
        return $this->_operationContext
            ->incomingRequest()
            ->getRequestHeader(ODataConstants::HTTPREQUEST_HEADER_IF_NONE);
    }      


    /**
     * Set the Cache-Control header on the response
     * 
     * @param string $value The cache-control value.
     * 
     * @return void
     * 
     @ throws InvalidOperation
     */
    public function setResponseCacheControl($value)
    {
        $this->_operationContext->outgoingResponse()->setCacheControl($value);
    }      

    /**
     * Gets the HTTP MIME type of the output stream
     * 
     * @return string
     */
    public function getResponseContentType()
    {
        return $this->_operationContext
            ->outgoingResponse()
            ->getContentType();
    }
        
    /**
     * Sets the HTTP MIME type of the output stream
     * 
     * @param string $value The HTTP MIME type
     * 
     * @return void
     */
    public function setResponseContentType($value)
    {
        $this->_operationContext
            ->outgoingResponse()
            ->setContentType($value);
    }      
        
    /**
     * Sets the content length of the output stream
     * 
     * @param string $value The content length
     * 
     * @return void
     * 
     * @throw Exception if $value is not numeric throws notAcceptableError
     */
    public function setResponseContentLength($value)
    {
        if (preg_match('/[0-9]+/', $value)) {
            $this->_operationContext->outgoingResponse()->setContentLength($value);
        } else {
            throw ODataException::notAcceptableError(
                "ContentLength:$value is invalid"
            );
        }
    }
    
    /**
     * Gets the value of the ETag header on the response
     * 
     * @return string|null
     */
    public function getResponseETag()
    {
        return $this->_operationContext->outgoingResponse()->getETag();
    }
        
    /**
     * Sets the value of the ETag header on the response
     * 
     * @param string $value The ETag value
     * 
     * @return void
     */
    public function setResponseETag($value)
    {
        $this->_operationContext->outgoingResponse()->setETag($value);
    }

    /**
     * Sets the value Location header on the response
     * 
     * @param string $value The location.
     * 
     * @return void
     */
    public function setResponseLocation($value)
    {
        $this->_operationContext->outgoingResponse()->setLocation($value);
    }

    /**
     * Sets the value status code header on the response
     * 
     * @param string $value The status code
     * 
     * @return void
     */
    public function setResponseStatusCode($value)
    {
        $floor = floor($value / 100);
        if ($floor >= 1 && $floor <= 5) {
            $statusDescription = HttpStatus::getStatusDescription($value);
            if (!is_null($statusDescription)) {
                $statusDescription = ' ' . $statusDescription;
            }

            $this->_operationContext
                ->outgoingResponse()->setStatusCode($value . $statusDescription);
        } else {
            throw ODataException::createInternalServerError(
                'Invalid Status Code' . $value
            );
        }
    }

    /**
     * Sets the value status description header on the response
     * 
     * @param string $value The status description
     * 
     * @return void
     */
    public function setResponseStatusDescription($value)
    {
        $this->_operationContext
            ->outgoingResponse()->setStatusDescription($value);
    }

    /**
     * Sets the value stream to be send a response
     * 
     * @param string &$value The stream
     * 
     * @return void
     */
    public function setResponseStream(&$value)
    {
        $this->_operationContext->outgoingResponse()->setStream($value);
    }

    /**
     * Sets the DataServiceVersion response header
     * 
     * @param string $value The version
     * 
     * @return void
     */
    public function setResponseVersion($value)
    {
        $this->_operationContext->outgoingResponse()->setServiceVersion($value);
    }

    /**
     * Get the response headers
     * 
     * @return array<headername, headerValue>
     */
    public function &getResponseHeaders()
    {
        return $this->_operationContext->outgoingResponse()->getHeaders();
    }

    /**
     * Add a header to response header collection
     * 
     * @param string $headerName  The name of the header
     * @param string $headerValue The value of the header
     * 
     * @return void
     */
    public function addResponseHeader($headerName, $headerValue)
    {
        $this->_operationContext
            ->outgoingResponse()->addHeader($headerName, $headerValue);
    }

	/**
	 * Translates the short $format forms into the full mime type forms
	 * @param Version $responseVersion the version scheme to interpret the short form with
	 * @param string $format the short $format form
	 * @return string the full mime type corresponding to the short format form for the given version
	 */
	public static function translateFormatToMime(Version $responseVersion, $format){
        //TODO: should the version switches be off of the requestVersion, not the response version? see #91

        switch($format) {

	        case ODataConstants::FORMAT_XML:
		        $format = MimeTypes::MIME_APPLICATION_XML;
		        break;

            case ODataConstants::FORMAT_ATOM:
	            $format = MimeTypes::MIME_APPLICATION_ATOM ;
		        break;

            case ODataConstants::FORMAT_VERBOSE_JSON:
                if($responseVersion == Version::v3()){
	                //only translatable in 3.0 systems
	                $format = MimeTypes::MIME_APPLICATION_JSON_VERBOSE;
                }
                break;

            case ODataConstants::FORMAT_JSON:
                if($responseVersion == Version::v3()){
                    $format = MimeTypes::MIME_APPLICATION_JSON_MINIMAL_META;
                } else{
	                $format = MimeTypes::MIME_APPLICATION_JSON;
                }
		        break;

        }

	    return $format . ';q=1.0';
    }



}