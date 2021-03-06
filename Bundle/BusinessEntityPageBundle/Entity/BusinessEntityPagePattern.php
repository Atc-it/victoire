<?php
namespace Victoire\Bundle\BusinessEntityPageBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Victoire\Bundle\CoreBundle\Entity\WebViewInterface;
use Victoire\Bundle\PageBundle\Entity\PageStatus;
use Victoire\Bundle\TemplateBundle\Entity\Template;

/**
 * BusinessEntityPagePattern
 *
 * @ORM\Entity(repositoryClass="Victoire\Bundle\BusinessEntityPageBundle\Repository\BusinessEntityPagePatternRepository")
 * @ORM\HasLifecycleCallbacks()
 */
class BusinessEntityPagePattern extends Template implements WebViewInterface
{
    //This trait add the query and business_entity_name columns
    use \Victoire\Bundle\QueryBundle\Entity\Traits\QueryTrait;
    use \Victoire\Bundle\PageBundle\Entity\Traits\WebViewTrait;

    const TYPE = 'business_entity_page_pattern';

    /**
     * @ORM\OneToMany(targetEntity="\Victoire\Bundle\BusinessEntityPageBundle\Entity\BusinessEntityPage", mappedBy="template")
     */
    protected $inheritors;

    /**
     * contruct
     **/
    public function __construct()
    {
        parent::__construct();
        $this->publishedAt = new \DateTime();
        $this->status = PageStatus::PUBLISHED;
    }

    public function getInstances() { return $this->inheritors; }
    public function setInstances($inheritors) { $this->inheritors = $inheritors; return $this; }
    public function getLayout() { return $this->getTemplate()->getLayout(); }

}
