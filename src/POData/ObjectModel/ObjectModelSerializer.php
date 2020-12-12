<?php

namespace POData\ObjectModel;

use POData\Common\ODataConstants;
use POData\Common\InvalidOperationException;
use POData\Providers\Query\QueryType;
use POData\UriProcessor\ResourcePathProcessor\SegmentParser\TargetSource;
use POData\UriProcessor\RequestDescription;
use POData\IService;
use POData\Providers\Metadata\ResourceType;
use POData\Providers\Metadata\ResourceTypeKind;
use POData\Providers\Metadata\ResourcePropertyKind;
use POData\Providers\Metadata\ResourceProperty;
use POData\Providers\ProvidersWrapper;
use POData\Providers\Metadata\Type\Binary;
use POData\Providers\Metadata\Type\Boolean;
use POData\Providers\Metadata\Type\String;
use POData\Providers\Metadata\Type\DateTime;
use POData\Common\ODataException;
use POData\Common\Messages;

/**
 * Class ObjectModelSerializer
 * @package POData\ObjectModel
 */
class ObjectModelSerializer extends ObjectModelSerializerBase
{
    /**
     * Creates new instance of ObjectModelSerializer.
     * 
     * @param IService $service
     *
     * @param RequestDescription $request the  request submitted by the client.
     *
     */
    public function __construct(IService $service, RequestDescription $request)
    {
        parent::__construct($service, $request);
    }

    /**
     * Write a top level entry resource.
     * 
     * @param mixed $entryObject Reference to the entry object to be written.
     * 
     * @return ODataEntry
     */
    public function writeTopLevelElement($entryObject)
    {
        $requestTargetSource = $this->request->getTargetSource();

        $resourceType = null;
        if ($requestTargetSource == TargetSource::ENTITY_SET) {
            $resourceType = $this->request->getTargetResourceType();
        } else {
            $this->assert(
                $requestTargetSource == TargetSource::PROPERTY,
                '$requestTargetSource == TargetSource::PROPERTY'
            );
            $resourceProperty = $this->request->getProjectedProperty();
            //$this->assert($resourceProperty->getKind() == ResourcePropertyKind::RESOURCE_REFERENCE, '$resourceProperty->getKind() == ResourcePropertyKind::RESOURCE_REFERENCE');
            $resourceType = $resourceProperty->getResourceType();
        }

        $needPop = $this->pushSegmentForRoot();
        $entry = $this->_writeEntryElement(
            $entryObject,
	        $resourceType,
            $this->request->getRequestUrl()->getUrlAsString(),
            $this->request->getContainerName()
        );
        $this->popSegment($needPop);
        return $entry;
    }

    /**
     * Write top level feed element.
     * 
     * @param array &$entryObjects Array of entry resources to be written.
     * 
     * @return ODataFeed.
     */
    public function writeTopLevelElements(&$entryObjects)
    {
        $this->assert(is_array($entryObjects), 'is_array($entryObjects)');
        $requestTargetSource = $this->request->getTargetSource();
        $title = null;
        if ($requestTargetSource == TargetSource::ENTITY_SET) {
            $title = $this->request->getContainerName();
        } else {
            $this->assert(
                $requestTargetSource == TargetSource::PROPERTY,
                '$requestTargetSource == TargetSource::PROPERTY'
            );            
            $resourceProperty = $this->request->getProjectedProperty();
            $this->assert(
                $resourceProperty->getKind() == ResourcePropertyKind::RESOURCESET_REFERENCE, 
                '$resourceProperty->getKind() == ResourcePropertyKind::RESOURCESET_REFERENCE'
            );
            $title = $resourceProperty->getName();
        }

        $relativeUri = $this->request->getIdentifier();
        $feed = new ODataFeed();

        if ($this->request->queryType == QueryType::ENTITIES_WITH_COUNT()) {
            $feed->rowCount = $this->request->getCountValue();
        }

        $needPop = $this->pushSegmentForRoot();
        $targetResourceType = $this->request->getTargetResourceType();
        $this->_writeFeedElements(
            $entryObjects,
            $targetResourceType,
            $title,
            $this->request->getRequestUrl()->getUrlAsString(),
            $relativeUri,
            $feed
        );
        $this->popSegment($needPop);
        return $feed;
    }

    /**
     * Write top level url element.
     * 
     * @param mixed $entryObject The entry resource whose url to be written.
     * 
     * @return ODataURL
     */
    public function writeUrlElement($entryObject)
    {
        $url = new ODataURL();
        if (!is_null($entryObject)) {
            $currentResourceType = $this->getCurrentResourceSetWrapper()->getResourceType();
            $relativeUri = $this->getEntryInstanceKey(
                $entryObject, 
                $currentResourceType,
                $this->getCurrentResourceSetWrapper()->getName()
            );
            
            $url->url = rtrim($this->absoluteServiceUri, '/') . '/' . $relativeUri;
        }

        return $url;
    }

    /**
     * Write top level url collection.
     * 
     * @param array $entryObjects Array of entry resources
     * whose url to be written.
     * 
     * @return ODataURLCollection
     */
    public function writeUrlElements($entryObjects)
    {
        $urls = new ODataURLCollection();
        if (!empty($entryObjects)) {        
            $i = 0;
            foreach ($entryObjects as $entryObject) {
                $urls->urls[$i] = $this->writeUrlElement($entryObject);
                $i++;
            }

            if ($i > 0 && $this->needNextPageLink(count($entryObjects))) {
                $urls->nextPageLink = $this->getNextLinkUri($entryObjects[$i - 1], $this->request->getRequestUrl()->getUrlAsString());
            }
        }

        if ($this->request->queryType == QueryType::ENTITIES_WITH_COUNT()) {
            $urls->count = $this->request->getCountValue();
        }

        return $urls;
    }

    /**
     * Write top level complex resource.
     * 
     * @param mixed                &$complexValue         The complex object to be 
     *                                                    written.
     * @param string               $propertyName          The name of the 
     *                                                    complex property.
     * @param ResourceType         &$resourceType         Describes the type of 
     *                                                    complex object.
     *
     * @return ODataPropertyContent
     */
    public function writeTopLevelComplexObject(
	    &$complexValue,
        $propertyName,
        ResourceType &$resourceType
    ) {
	    $propertyContent = new ODataPropertyContent();
        $this->_writeComplexValue(
            $complexValue,
            $propertyName, $resourceType, null,
	        $propertyContent
        );

	    return $propertyContent;
    }

    /**
     * Write top level bag resource.
     * 
     * @param mixed                &$BagValue             The bag object to be 
     *                                                    written.
     * @param string               $propertyName          The name of the 
     *                                                    bag property.
     * @param ResourceType         &$resourceType         Describes the type of 
     *                                                    bag object.
     *
     * @return ODataPropertyContent
     */
    public function writeTopLevelBagObject(
	    &$BagValue,
        $propertyName,
        ResourceType &$resourceType
    ) {

	    $propertyContent = new ODataPropertyContent();
        $this->_writeBagValue(
            $BagValue,
            $propertyName, $resourceType, null,
	        $propertyContent
        );

	    return $propertyContent;
    }

    /**
     * Write top level primitive value.
     * 
     * @param mixed                &$primitiveValue       The primitve value to be 
     *                                                    written.
     * @param ResourceProperty     &$resourceProperty     Resource property 
     *                                                    describing the 
     *                                                    primitive property 
     *                                                    to be written. 
     *
     * @return ODataPropertyContent
     */
    public function writeTopLevelPrimitive(
	    &$primitiveValue,
        ResourceProperty &$resourceProperty
    ) {
	    $propertyContent = new ODataPropertyContent();
	    $propertyContent->properties[] = new ODataProperty();
        $this->_writePrimitiveValue(
            $primitiveValue, 
            $resourceProperty,
	        $propertyContent->properties[0]
        );

	    return $propertyContent;
    }

    /**
     * Write an entry element.
     * 
     * @param mixed        $entryObject  Object representing entry element.
     * @param ResourceType $resourceType Expected type of the entry object.
     * @param string       $absoluteUri   Absolute uri of the entry element.
     * @param string       $relativeUri   Relative uri of the entry element.
     *
     * @return ODataEntry
     */
    private function _writeEntryElement(
        $entryObject,
        ResourceType $resourceType,
        $absoluteUri,
        $relativeUri
    ) {
	    $entry = new ODataEntry();
	    $entry->resourceSetName = $this->getCurrentResourceSetWrapper()->getName();

        if (is_null($entryObject)) {
            //According to atom standard an empty entry must have an Author
            //node.
        } else {
            $relativeUri = $this->getEntryInstanceKey(
                $entryObject, 
                $resourceType,
                $this->getCurrentResourceSetWrapper()->getName()
            );

            $absoluteUri = rtrim($this->absoluteServiceUri, '/') . '/' . $relativeUri;
            $title = $resourceType->getName();
            //TODO Resolve actual resource type
            $actualResourceType = $resourceType;
            $this->_writeMediaResourceMetadata(
                $entryObject,
                $actualResourceType,
                $title,
                $relativeUri,
	            $entry
            );

	        $entry->id = $absoluteUri;
	        $entry->eTag = $this->getETagForEntry($entryObject, $resourceType);
	        $entry->title = $title;
	        $entry->editLink = $relativeUri;
	        $entry->type = $actualResourceType->getFullName();
            $odataPropertyContent = new ODataPropertyContent();
            $this->_writeObjectProperties(
                $entryObject, 
                $actualResourceType,
                $absoluteUri,
                $relativeUri,
	            $entry,
                $odataPropertyContent
            );
	        $entry->propertyContent = $odataPropertyContent;
        }

	    return $entry;
    }

    /**
     * Writes the feed elements
     * 
     * @param array        &$entryObjects Array of entries in the feed element.
     * @param ResourceType &$resourceType The resource type of the f the elements 
     *                                    in the collection.
     * @param string       $title         Title of the feed element.
     * @param string       $absoluteUri   Absolute uri representing the feed element.
     * @param string       $relativeUri   Relative uri representing the feed element.
     * @param ODataFeed    &$feed    Feed to write to.
     * 
     * @return void
     */
    private function _writeFeedElements(
        &$entryObjects,
        ResourceType &$resourceType,
        $title,
        $absoluteUri,
        $relativeUri,
        ODataFeed &$feed
    ) {
        $this->assert(is_array($entryObjects), '_writeFeedElements::is_array($entryObjects)');
        $feed->id = $absoluteUri;
        $feed->title = $title;
        $feed->selfLink = new ODataLink();
        $feed->selfLink->name = ODataConstants::ATOM_SELF_RELATION_ATTRIBUTE_VALUE;
        $feed->selfLink->title =  $title;
        $feed->selfLink->url = $relativeUri;
        
        if (empty($entryObjects)) {
            //TODO // ATOM specification: if a feed contains no entries, 
            //then the feed should have at least one Author tag
        } else {
            foreach ($entryObjects as $entryObject) {
                $feed->entries[] = $this->_writeEntryElement($entryObject, $resourceType, null, null);
            }

            if ($this->needNextPageLink(count($entryObjects))) {
                $feed->nextPageLink = $this->getNextLinkUri(end($entryObjects), $absoluteUri);
            }
        }
    }

    /**
     * Write values of properties of given entry (resource) or complex object.
     * 
     * @param mixed                $customObject          Entity or complex object 
     *                                                    with properties  
     *                                                    to write out.
     * @param ResourceType         &$resourceType         Resource type describing 
     *                                                    the metadata of 
     *                                                    the custom object.
     * @param string               $absoluteUri           Absolute uri for the given 
     *                                                    entry object 
     *                                                    NULL for complex object.
     * @param string               $relativeUri           Relative uri for the given 
     *                                                    custom object.
     * @param ODataEntry           &$odataEntry           ODataEntry instance to 
     *                                                    place links and
     *                                                    expansion of the 
     *                                                    entry object, 
     *                                                    NULL for complex object.
     * @param ODataPropertyContent &$odataPropertyContent ODataPropertyContent
     *                                                    instance in which
     *                                                    to place the values.
     * 
     * @return void
     */
    private function _writeObjectProperties(
	    $customObject,
        ResourceType &$resourceType, 
        $absoluteUri, 
        $relativeUri, 
        &$odataEntry, 
        ODataPropertyContent &$odataPropertyContent
    ) {
        $resourceTypeKind = $resourceType->getResourceTypeKind();
        if (is_null($absoluteUri) == ($resourceTypeKind == ResourceTypeKind::ENTITY)
        ) {
            throw ODataException::createInternalServerError(
                Messages::badProviderInconsistentEntityOrComplexTypeUsage(
                    $resourceType->getName()
                )
            );
        }

        $this->assert(
            (($resourceTypeKind == ResourceTypeKind::ENTITY) && ($odataEntry instanceof ODataEntry)) 
            || (($resourceTypeKind == ResourceTypeKind::COMPLEX) && is_null($odataEntry)), 
            '(($resourceTypeKind == ResourceTypeKind::ENTITY) && ($odataEntry instanceof ODataEntry)) 
            || (($resourceTypeKind == ResourceTypeKind::COMPLEX) && is_null($odataEntry))'
        );
        $projectionNodes = null;
        $navigationProperties = null;
        if ($resourceTypeKind == ResourceTypeKind::ENTITY) {
            $projectionNodes = $this->getProjectionNodes();
            $navigationProperties = array();
        }

        if (is_null($projectionNodes)) {
            //This is the code path to handle properties of Complex type 
            //or Entry without projection (i.e. no expansion or selection)          
            $resourceProperties = array(); 
            if ($resourceTypeKind == ResourceTypeKind::ENTITY) {
                // If custom object is an entry then it can contain navigation 
                // properties which are invisible (because the corresponding 
                // resource set is invisible).  
                // IDSMP::getResourceProperties will give collection of properties
                // which are visible.
                $currentResourceSetWrapper1 = $this->getCurrentResourceSetWrapper();
                $resourceProperties = $this->service
                    ->getProvidersWrapper()
                    ->getResourceProperties(
                        $currentResourceSetWrapper1, 
                        $resourceType
                    );
            } else {
                $resourceProperties = $resourceType->getAllProperties();
            }

            //First write out primitve types
            foreach ($resourceProperties as $name => $resourceProperty) {
                if ($resourceProperty->getKind() == ResourcePropertyKind::PRIMITIVE 
                    || $resourceProperty->getKind() == (ResourcePropertyKind::PRIMITIVE | ResourcePropertyKind::KEY) 
                    || $resourceProperty->getKind() == (ResourcePropertyKind::PRIMITIVE | ResourcePropertyKind::ETAG)
                    || $resourceProperty->getKind() == (ResourcePropertyKind::PRIMITIVE | ResourcePropertyKind::KEY | ResourcePropertyKind::ETAG)
                ) {
                    $odataProperty = new ODataProperty();
                    $primitiveValue = $this->getPropertyValue($customObject, $resourceType, $resourceProperty);
                    $this->_writePrimitiveValue($primitiveValue, $resourceProperty, $odataProperty);
                    $odataPropertyContent->properties[] = $odataProperty;
                }
            }

            //Write out bag and complex type
            $i = 0;
            foreach ($resourceProperties as $resourceProperty) {                
                if ($resourceProperty->isKindOf(ResourcePropertyKind::BAG)) {
                    //Handle Bag Property (Bag of Primitive or complex)
                    $propertyValue = $this->getPropertyValue($customObject, $resourceType, $resourceProperty);
                    $resourceType2 = $resourceProperty->getResourceType();
                    $this->_writeBagValue(
                        $propertyValue,
                        $resourceProperty->getName(),
                        $resourceType2,
                        $relativeUri . '/' . $resourceProperty->getName(),
                        $odataPropertyContent
                    );
                } else {
                    $resourcePropertyKind = $resourceProperty->getKind();
                    if ($resourcePropertyKind == ResourcePropertyKind::COMPLEX_TYPE) {
                        $propertyValue = $this->getPropertyValue($customObject, $resourceType, $resourceProperty);
                        $resourceType1 = $resourceProperty->getResourceType();
                        $this->_writeComplexValue(
                            $propertyValue,
                            $resourceProperty->getName(),
                            $resourceType1,
                            $relativeUri . '/' . $resourceProperty->getName(),
                            $odataPropertyContent
                        );
                    } else if ($resourceProperty->getKind() == ResourcePropertyKind::PRIMITIVE 
                        || $resourceProperty->getKind() == (ResourcePropertyKind::PRIMITIVE | ResourcePropertyKind::KEY) 
                        || $resourceProperty->getKind() == (ResourcePropertyKind::PRIMITIVE | ResourcePropertyKind::ETAG)
                        || $resourceProperty->getKind() == (ResourcePropertyKind::PRIMITIVE | ResourcePropertyKind::KEY | ResourcePropertyKind::ETAG)
                    ) {
                        continue;  
                    } else {
                         $this->assert(
                             ($resourcePropertyKind == ResourcePropertyKind::RESOURCE_REFERENCE)
                             || ($resourcePropertyKind == ResourcePropertyKind::RESOURCESET_REFERENCE), 
                             '($resourcePropertyKind == ResourcePropertyKind::RESOURCE_REFERENCE)
                             || ($resourcePropertyKind == ResourcePropertyKind::RESOURCESET_REFERENCE)'
                         );

                        $navigationProperties[$i] = new NavigationPropertyInfo($resourceProperty, $this->shouldExpandSegment($resourceProperty->getName()));
                        if ($navigationProperties[$i]->expanded) {
                            $navigationProperties[$i]->value = $this->getPropertyValue($customObject, $resourceType, $resourceProperty);
                        }

                        $i++;                        
                    }
                }
            }
                
        } else { //This is the code path to handle projected properties of Entry
            $i = 0;
            foreach ($projectionNodes as $projectionNode) {
                $propertyName = $projectionNode->getPropertyName();
                $resourceProperty = $resourceType->resolveProperty($propertyName);
                $this->assert(!is_null($resourceProperty), '!is_null($resourceProperty)');
                
                if ($resourceProperty->getTypeKind() == ResourceTypeKind::ENTITY) {
                    $currentResourceSetWrapper2 = $this->getCurrentResourceSetWrapper();
                    $resourceProperties = $this->service
                        ->getProvidersWrapper()
                        ->getResourceProperties(
                            $currentResourceSetWrapper2, 
                            $resourceType
                        );
                    //Check for the visibility of this navigation property
                    if (array_key_exists($resourceProperty->getName(), $resourceProperties)) {
                        $navigationProperties[$i] = new NavigationPropertyInfo($resourceProperty, $this->shouldExpandSegment($propertyName));
                        if ($navigationProperties[$i]->expanded) {
                            $navigationProperties[$i]->value = $this->getPropertyValue($customObject, $resourceType, $resourceProperty);
                        }

                        $i++;
                        continue;
                    }
                }

                //Primitve, complex or bag property
                $propertyValue = $this->getPropertyValue($customObject, $resourceType, $resourceProperty);
                $propertyTypeKind = $resourceProperty->getKind();
                $propertyResourceType = $resourceProperty->getResourceType();
                $this->assert(!is_null($propertyResourceType), '!is_null($propertyResourceType)');
                if (ResourceProperty::sIsKindOf($propertyTypeKind, ResourcePropertyKind::BAG)) {
                    $bagResourceType = $resourceProperty->getResourceType();
                    $this->_writeBagValue(
                        $propertyValue,
                        $propertyName,
                        $bagResourceType,
                        $relativeUri . '/' . $propertyName,
                        $odataPropertyContent
                    );
                } else if (ResourceProperty::sIsKindOf($propertyTypeKind, ResourcePropertyKind::PRIMITIVE)) {
                    $odataProperty = new ODataProperty();
                    $this->_writePrimitiveValue($propertyValue, $resourceProperty, $odataProperty);
                    $odataPropertyContent->properties[] = $odataProperty;
                } else if ($propertyTypeKind == ResourcePropertyKind::COMPLEX_TYPE) {
                    $complexResourceType = $resourceProperty->getResourceType();
                    $this->_writeComplexValue(
                        $propertyValue,
                        $propertyName,
                        $complexResourceType,
                        $relativeUri . '/' . $propertyName,
                        $odataPropertyContent
                    );
                } else {
                    //unexpected
                    $this->assert(false, '$propertyTypeKind = Primitive or Bag or ComplexType');
                }
            }
        }

        if (!is_null($navigationProperties)) {
            //Write out navigation properties (deferred or inline)
            foreach ($navigationProperties as $navigationPropertyInfo) {
                $propertyName = $navigationPropertyInfo->resourceProperty->getName();
                $type = $navigationPropertyInfo->resourceProperty->getKind() == ResourcePropertyKind::RESOURCE_REFERENCE ? 
                    'application/atom+xml;type=entry':
                    'application/atom+xml;type=feed';
                $link = new ODataLink();
                $link->name = ODataConstants::ODATA_RELATED_NAMESPACE . $propertyName; 
                $link->title = $propertyName;
                $link->type = $type;
                $link->url = $relativeUri . '/' . $propertyName;

                if ($navigationPropertyInfo->expanded) {
                    $propertyRelativeUri = $relativeUri . '/' . $propertyName;
                    $propertyAbsoluteUri = trim($absoluteUri, '/') . '/' . $propertyName;
                    $needPop = $this->pushSegmentForNavigationProperty($navigationPropertyInfo->resourceProperty);
                    $navigationPropertyKind = $navigationPropertyInfo->resourceProperty->getKind();
                    $this->assert(
                        $navigationPropertyKind == ResourcePropertyKind::RESOURCESET_REFERENCE 
                        || $navigationPropertyKind == ResourcePropertyKind::RESOURCE_REFERENCE, 
                        '$navigationPropertyKind == ResourcePropertyKind::RESOURCESET_REFERENCE 
                        || $navigationPropertyKind == ResourcePropertyKind::RESOURCE_REFERENCE'
                    );
                    $currentResourceSetWrapper = $this->getCurrentResourceSetWrapper();
                    $this->assert(!is_null($currentResourceSetWrapper), '!is_null($currentResourceSetWrapper)');
                    $link->isExpanded = true;
                    if (!is_null($navigationPropertyInfo->value)) {
                        if ($navigationPropertyKind == ResourcePropertyKind::RESOURCESET_REFERENCE) {
                            $inlineFeed = new ODataFeed();
                            $link->isCollection = true;
                            $currentResourceType = $currentResourceSetWrapper->getResourceType();
                            $this->_writeFeedElements(
                                $navigationPropertyInfo->value,
                                $currentResourceType, 
                                $propertyName, 
                                $propertyAbsoluteUri, 
                                $propertyRelativeUri, 
                                $inlineFeed
                            );
                            $link->expandedResult = $inlineFeed;
                        } else {

                            $link->isCollection = false;
                            $currentResourceType1 = $currentResourceSetWrapper->getResourceType();

                            $link->expandedResult = $this->_writeEntryElement(
	                            $navigationPropertyInfo->value,
	                            $currentResourceType1,
	                            $propertyAbsoluteUri,
	                            $propertyRelativeUri
                            );
                        }
                    } else {
                        $link->expandedResult = null;
                    }

                    $this->popSegment($needPop);
                }

                $odataEntry->links[] = $link;
            }
        }
    }

    /**
     * Writes a primitive value and related information to the given
     * ODataProperty instance.
     * 
     * @param mixed            &$primitiveValue   The primitive value to write.
     * @param ResourceProperty &$resourceProperty The metadata of the primitive
     *                                            property value.
     * @param ODataProperty    &$odataProperty    ODataProperty instance to which
     *                                            the primitive value and related
     *                                            information to write out.
     *
     * @throws ODataException If given value is not primitive.
     * 
     * @return void
     */
    private function _writePrimitiveValue(&$primitiveValue, 
        ResourceProperty &$resourceProperty, ODataProperty &$odataProperty
    ) {
        if (is_object($primitiveValue)) {
            //TODO ERROR: The property 'PropertyName' 
            //is defined as primitive type but value is an object
        }

        
        $odataProperty->name = $resourceProperty->getName();
        $odataProperty->typeName = $resourceProperty->getInstanceType()->getFullTypeName();
        if (is_null($primitiveValue)) {
            $odataProperty->value = null;
        } else {
            $resourceType = $resourceProperty->getResourceType();
            $this->_primitiveToString(
                $resourceType,
                $primitiveValue,
                $odataProperty->value
            );
        }
    }

    /**
     * Write value of a complex object.
     * 
     * @param mixed                &$complexValue         Complex object to write.
     * @param string               $propertyName          Name of the 
     *                                                    complex property
     *                                                    whose value need 
     *                                                    to be written.
     * @param ResourceType         &$resourceType         Expected type 
     *                                                    of the property.
     * @param string               $relativeUri           Relative uri for the 
     *                                                    complex type element.
     * @param ODataPropertyContent &$odataPropertyContent Content to write to.
     * 
     * @return void
     */
    private function _writeComplexValue(&$complexValue,
        $propertyName, ResourceType &$resourceType, $relativeUri,
        ODataPropertyContent &$odataPropertyContent
    ) {
        $odataProperty = new ODataProperty();
        $odataProperty->name = $propertyName;
        if (is_null($complexValue)) {
            $odataProperty->value = null;
            $odataProperty->typeName = $resourceType->getFullName();
        } else {
            $content = new ODataPropertyContent();
            $actualType = $this->_complexObjectToContent(
                $complexValue,
                $propertyName,
                $resourceType,
                $relativeUri,
                $content
            );

            $odataProperty->typeName = $actualType->getFullName();
            $odataProperty->value = $content;
        }

        $odataPropertyContent->properties[] = $odataProperty;
    }

    /**
     * Write value of a bag instance.
     *
     * @param array/NULL           &$BagValue             Bag value to write.
     * @param string               $propertyName          Property name of the bag.
     * @param ResourceType         &$resourceType         Type describing the 
     *                                                    bag value.
     * @param string               $relativeUri           Relative Url to the bag.
     * @param ODataPropertyContent &$odataPropertyContent On return, this object 
     *                                                    will hold bag value which 
     *                                                    can be used by writers.
     * 
     * @return void
     */
    private function _writeBagValue(&$BagValue,
        $propertyName, ResourceType &$resourceType, $relativeUri,
        ODataPropertyContent &$odataPropertyContent
    ) {
        $bagItemResourceTypeKind = $resourceType->getResourceTypeKind();
        $this->assert(
            $bagItemResourceTypeKind == ResourceTypeKind::PRIMITIVE
            || $bagItemResourceTypeKind == ResourceTypeKind::COMPLEX,
            '$bagItemResourceTypeKind == ResourceTypeKind::PRIMITIVE
            || $bagItemResourceTypeKind == ResourceTypeKind::COMPLEX'
        );

        $odataProperty = new ODataProperty();
        $odataProperty->name = $propertyName;
        $odataProperty->typeName = 'Collection(' . $resourceType->getFullName() .')';
        if (is_null($BagValue) || (is_array($BagValue) && empty ($BagValue))) {
            $odataProperty->value = null;
        } else {
            $odataBagContent = new ODataBagContent();
            foreach ($BagValue as $itemValue) {
                if (!is_null($itemValue)) {
                    if ($bagItemResourceTypeKind == ResourceTypeKind::PRIMITIVE) {
                        $primitiveValueAsString = null;
                        $this->_primitiveToString($resourceType, $itemValue, $primitiveValueAsString);
                        $odataBagContent->propertyContents[] = $primitiveValueAsString;
                    } else if ($bagItemResourceTypeKind == ResourceTypeKind::COMPLEX) {
                        $complexContent = new ODataPropertyContent();
                        $actualType = $this->_complexObjectToContent(
                            $itemValue,
                            $propertyName,
                            $resourceType,
                            $relativeUri,
                            $complexContent
                        );
                        //TODO add type in case of base type
                        $odataBagContent->propertyContents[] = $complexContent;
                    }
                }
            }

            $odataProperty->value = $odataBagContent;
        }

        $odataPropertyContent->properties[] = $odataProperty;
    }

    /**
     * Write media resource metadata (for MLE and Named Streams)
     * 
     * @param mixed        $entryObject  The entry instance being serialized.
     * @param ResourceType &$resourceType Resource type of the entry instance.
     * @param string       $title         Title for the current 
     *                                    current entry instance.
     * @param string       $relativeUri   Relative uri for the 
     *                                    current entry instance.
     * @param ODataEntry   &$odataEntry   OData entry to write to.
     * 
     * @return void
     */
    private function _writeMediaResourceMetadata(
        $entryObject,
        ResourceType &$resourceType,
        $title,
        $relativeUri,
        ODataEntry &$odataEntry
    ) {
        if ($resourceType->isMediaLinkEntry()) {
            $odataEntry->isMediaLinkEntry = true;
            $streamProvider = $this->service->getStreamProvider();
            $eTag = $streamProvider->getStreamETag($entryObject, null);
            $readStreamUri = $streamProvider->getReadStreamUri($entryObject, null, $relativeUri);
            $mediaContentType = $streamProvider->getStreamContentType($entryObject, null);
            $mediaLink = new ODataMediaLink(
                $title,
                $streamProvider->getDefaultStreamEditMediaUri($relativeUri, null),
                $readStreamUri,
                $mediaContentType,
                $eTag
            );

            $odataEntry->mediaLink = $mediaLink;
        }

        if ($resourceType->hasNamedStream()) {
            foreach ($resourceType->getAllNamedStreams() as $title => $resourceStreamInfo) {
                $eTag = $streamProvider->getStreamETag($entryObject, $resourceStreamInfo);
                $readStreamUri = $streamProvider->getReadStreamUri($entryObject, $resourceStreamInfo, $relativeUri);
                $mediaContentType = $streamProvider->getStreamContentType($entryObject, $resourceStreamInfo);
                $odataEntry->mediaLinks[] = new ODataMediaLink(
                    $title,
                    $streamProvider->getDefaultStreamEditMediaUri($relativeUri, $resourceStreamInfo),
                    $readStreamUri,
                    $mediaContentType,
                    $eTag
                );
            }
        }
    }
    /**
     * Convert the given primitive value to string.
     * Note: This method will not handle null primitive value.
     * 
     * @param ResourceType &$primtiveResourceType Type of the primitive property
     *                                            whose value need to be converted.
     * @param mixed        $primitiveValue        Primitive value to convert.
     * @param string       &$stringValue          On return, this parameter will
     *                                            contain converted value.
     *                                            
     * @return void
     */
    private function _primitiveToString(ResourceType &$primtiveResourceType,
        $primitiveValue, &$stringValue
    ) {
        $type = $primtiveResourceType->getInstanceType();
        if ($type instanceof Boolean) {
            $stringValue = ($primitiveValue === true) ? 'true' : 'false';
        } else if ($type instanceof Binary) {
            $stringValue = base64_encode($primitiveValue);
	} else if ($type instanceof DateTime && $primitiveValue instanceOf \DateTime) {
            $stringValue = $primitiveValue->format(\DateTime::ATOM);
        } else if ($type instanceof String) {
            $stringValue = htmlspecialchars($primitiveValue, NULL, 'UTF-8'); // utf8_encode($primitiveValue) was troubling ascent chars.
	    $stringValue = str_replace('&amp;', '&', $stringValue); // special exception to allow (Name & Value) be retained.
        } else {        
            $stringValue = strval($primitiveValue);
        }
    }

    /**
     * Write value of a complex object.
     * Note: This method will not handle null complex value.
     *
     * @param mixed                &$complexValue         Complex object to write.
     * @param string               $propertyName          Name of the 
     *                                                    complex property
     *                                                    whose value 
     *                                                    need to be written.
     * @param ResourceType         &$resourceType         Expected type of the 
     *                                                    property.
     * @param string               $relativeUri           Relative uri for the 
     *                                                    complex type element.
     * @param ODataPropertyContent &$odataPropertyContent Content to write to.
     *
     * @return ResourceType The actual type of the complex object.
     * 
     * @return void
     */
    private function _complexObjectToContent(&$complexValue,
        $propertyName, ResourceType &$resourceType, $relativeUri,
        ODataPropertyContent &$odataPropertyContent
    ) {
        $count = count($this->complexTypeInstanceCollection);
        for ($i = 0; $i < $count; $i++) {
            if ($this->complexTypeInstanceCollection[$i] === $complexValue) {
                throw new InvalidOperationException(Messages::objectModelSerializerLoopsNotAllowedInComplexTypes($propertyName));
            }
        }

        $this->complexTypeInstanceCollection[$count] = &$complexValue;

        //TODO function to resolve actual type from $resourceType
        $actualType = $resourceType;
        $odataEntry = null;
        $this->_writeObjectProperties(
            $complexValue, $actualType,
            null, $relativeUri, $odataEntry, $odataPropertyContent
        );
        unset($this->complexTypeInstanceCollection[$count]);
        return $actualType;
    }
}
