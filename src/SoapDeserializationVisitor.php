<?php

namespace DMT\Soap\Serializer;

use JMS\Serializer\Exception\InvalidArgumentException;
use JMS\Serializer\XmlDeserializationVisitor;

/**
 * Class SoapDeserializationVisitor
 *
 * @package DMT\Soap
 */
class SoapDeserializationVisitor extends XmlDeserializationVisitor implements SoapNamespaceInterface
{
    /**
     * @param string $data
     *
     * @return mixed|\SimpleXMLElement
     * @throws InvalidArgumentException
     * @throws SoapFaultException
     */
    public function prepare($data)
    {
        /** @var \SimpleXMLElement $element */
        $element = parent::prepare($data);

        $version = array_search(current($element->getNamespaces()), static::SOAP_NAMESPACES);
        if (!$version) {
            throw new InvalidArgumentException('Unsupported SOAP version');
        }

        $element = $this->moveChildNamespacesToEnvelope($element);

        $messages = $element->xpath('*[local-name()="Body"]/*');
        if (count($messages) === 1) {
            $element = $messages[0];
        }

        if ($element->getName() === 'Fault') {
            if ($version == static::SOAP_1_1) {
                $this->throwSoap11Fault($element);
            } else {
                $this->throwSoap12Fault($element);
            }
        }

        return $element;
    }

    /**
     * Move all underlying namespaces to root element.
     *
     * @param \SimpleXMLElement $element
     *
     * @return \SimpleXMLElement
     */
    protected function moveChildNamespacesToEnvelope(\SimpleXMLElement $element): \SimpleXMLElement
    {
        $dom = dom_import_simplexml($element);

        foreach ($element->getNamespaces(true) as $prefix => $namespace) {
            if (!in_array($namespace, $element->getDocNamespaces())) {
                $dom->setAttributeNS('http://www.w3.org/2000/xmlns/', $prefix ? 'xmlns:' . $prefix : 'xmlns', $namespace);
            }
        }

        return parent::prepare($dom->ownerDocument->saveXML());
    }

    /**
     * @param \SimpleXMLElement $fault
     *
     * @throws SoapFaultException
     */
    protected function throwSoap11Fault(\SimpleXMLElement $fault)
    {
        $faultcode = $faultstring = '';
        $faultactor = $detail = null;

        extract($this->elementToArray($fault), EXTR_IF_EXISTS);

        throw new SoapFaultException($this->removePrefix($faultcode), $faultstring, $faultactor, $detail);
    }

    /**
     * @param \SimpleXMLElement $fault
     *
     * @throws SoapFaultException
     */
    protected function throwSoap12Fault(\SimpleXMLElement $fault)
    {
        $lang = substr(locale_get_default(), 0, 2);
        $path = '*[local-name()="Reason"]/*[local-name()="Text" and @xml:lang="' . $lang . '"]';

        $messages = $fault->xpath($path);
        if (count($messages) === 0) {
            $messages = $fault->xpath('*[local-name()="Reason"]/*[local-name()="Text"]');
        }

        $code = array_map(
            [$this, 'removePrefix'],
            $fault->xpath('//*[local-name()="Code" or local-name()="Subcode"]/*[local-name()="Value"]')
        );

        $node = $fault->xpath('*[local-name()="Node"]')[0] ?? null;
        $detail = $fault->xpath('*[local-name()="Detail"]')[0] ?? null;

        if ($detail !== null) {
            $detail = $this->elementToArray($detail);
        }

        throw new SoapFaultException(implode('.', $code), $messages[0], $node, $detail);
    }

    /**
     * @param \SimpleXMLElement $element
     *
     * @return array
     */
    protected function elementToArray(\SimpleXMLElement $element): array
    {
        $result = [];
        foreach ($element->xpath('*') as $node) {
            if (count($node->xpath('*')) > 0) {
                $value = $this->elementToArray($node);
            } else {
                $value = strval($node);
            }
            $result = array_merge_recursive($result, [$node->getName() => $value]);
        }

        return $result;
    }

    /**
     * Remove a prefix from text node.
     *
     * @param string $value A node value containing a namespace prefix, eg SOAP-ENV:Client.
     *
     * @return string
     */
    protected function removePrefix(string $value): string
    {
        return preg_replace('~^(.+:)?(.*)$~', "$2", $value);
    }
}
