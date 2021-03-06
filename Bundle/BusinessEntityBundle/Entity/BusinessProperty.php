<?php
namespace Victoire\Bundle\BusinessEntityBundle\Entity;

/**
 *
 */
class BusinessProperty
{
    protected $type = null;
    protected $entityProperty = null;

    /**
     * Set the type
     *
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * @return string the type of business property
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Get the property that is tagged
     *
     * @return string The entity property
     */
    public function getEntityProperty()
    {
        return $this->entityProperty;
    }

    /**
     * Set the entity property
     * @param string $property
     */
    public function setEntityProperty($property)
    {
        $this->entityProperty = $property;
    }

    /**
     * Display object as string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->entityProperty;
    }
}
