<?php

namespace Victoire\Bundle\CoreBundle\Helper;

use Doctrine\Orm\EntityManager;
use Gedmo\Sluggable\Util\Urlizer;
use Victoire\Bundle\BusinessEntityBundle\Converter\ParameterConverter as BETParameterConverter;
use Victoire\Bundle\BusinessEntityBundle\Helper\BusinessEntityHelper;
use Victoire\Bundle\BusinessEntityPageBundle\Entity\BusinessEntityPage;
use Victoire\Bundle\BusinessEntityPageBundle\Entity\BusinessEntityPagePattern;
use Victoire\Bundle\BusinessEntityPageBundle\Helper\BusinessEntityPageHelper;
use Victoire\Bundle\CoreBundle\Entity\View;
use Victoire\Bundle\PageBundle\Entity\BasePage;
use Victoire\Bundle\TemplateBundle\Entity\Template;
use Victoire\Bundle\TwigBundle\Entity\ErrorPage;
use Victoire\Widget\LayoutBundle\Entity\WidgetLayout;

/**
 * Page helper
 * ref: victoire_core.view_helper
 */
class ViewHelper
{
    protected $parameterConverter;
    protected $businessEntityHelper;
    protected $businessEntityPageHelper;
    protected $em;
    protected $viewCacheHelper;

    /**
     * Constructor
     * @param BETParameterConverter    $parameterConverter
     * @param BusinessEntityHelper     $businessEntityHelper
     * @param BusinessEntityPageHelper $businessEntityPageHelper
     * @param EntityManager            $entityManager
     * @param ViewCacheHelper          $viewCacheHelper
     */
    public function __construct(
        BETParameterConverter $parameterConverter,
        BusinessEntityHelper $businessEntityHelper,
        BusinessEntityPageHelper $businessEntityPageHelper,
        EntityManager $entityManager,
        ViewCacheHelper $viewCacheHelper
    ) {
        $this->parameterConverter = $parameterConverter;
        $this->businessEntityHelper = $businessEntityHelper;
        $this->businessEntityPageHelper = $businessEntityPageHelper;
        $this->em = $entityManager;
        $this->viewCacheHelper = $viewCacheHelper;
    }

    //@todo Make it dynamic please
    protected $pageParameters = array(
        'name',
        'bodyId',
        'bodyClass',
        'slug',
        'url',
        'locale',
    );

    public function buildViewsReferences($views)
    {
        $viewsReferences = array();
        foreach ($views as $view) {
            $viewsReferences = array_merge($viewsReferences, $this->buildViewReference($view));
            $this->em->refresh($view);
        }

        $this->cleanVirtualViews($viewsReferences);

        return $viewsReferences;
    }

    public function cleanVirtualViews(&$viewsReferences)
    {
        foreach ($viewsReferences as $viewReference) {
            // If viewReference is a persisted page
            if ($viewReference['viewNamespace'] == 'Victoire\Bundle\BusinessEntityPageBundle\Entity\BusinessEntityPage') {
                array_walk($viewsReferences, function ($_viewReference, $key) use ($viewReference, &$viewsReferences) {
                    if ($_viewReference['viewNamespace'] == 'Victoire\Bundle\BusinessEntityPageBundle\Entity\BusinessEntityPagePattern'
                        && !empty($_viewReference['entityId'])
                        && $_viewReference['entityId'] == $viewReference['entityId']) {
                        unset($viewsReferences[$key]);
                    }
                });
            }
        }
    }
    /**
     * This method get all views (BasePage and Template) in DB and return the references, including non persisted Business entity page (pattern and businessEntityName based)
     * @return array the computed views as array
     */
    public function getAllViewsReferences()
    {
        $viewsReferences = $this->viewCacheHelper->convertXmlCacheToArray();

        return $viewsReferences;
    }

    /**
     * Generate update the page parameters with the entity
     *
     * @param BasePage $page
     * @param Entity   $entity
     */
    public function updatePageParametersByEntity(BusinessEntityPage $page, $entity)
    {
        //if no entity is provided
        if ($entity === null) {
            //we look for the entity of the page
            if ($page->getBusinessEntity() !== null) {
                $entity = $page->getBusinessEntity();
            }
        }

        //only if we have an entity instance
        if ($entity !== null) {
            $businessEntity = $this->businessEntityHelper->findByEntityInstance($entity);

            if ($businessEntity !== null) {
                $businessProperties = $this->businessEntityPageHelper->getBusinessProperties($businessEntity);

                //parse the business properties
                foreach ($businessProperties as $businessProperty) {
                    //parse of seo attributes
                    foreach ($this->pageParameters as $pageAttribute) {
                        $string = $this->getEntityAttributeValue($page, $pageAttribute);
                        $updatedString = $this->parameterConverter->setBusinessPropertyInstance($string, $businessProperty, $entity);
                        $this->setEntityAttributeValue($page, $pageAttribute, $updatedString);
                    }
                }

                $urlizer = new Urlizer();
                $page->setSlug($urlizer->urlize($page->getName()));
            }
        }
    }

    /**
     * Get the content of an attribute of an entity given
     *
     * @param BusinessEntityPage $entity
     * @param strin              $field
     *
     * @return mixed
     */
    protected function getEntityAttributeValue($entity, $field)
    {
        $functionName = 'get'.ucfirst($field);

        $fieldValue = call_user_func(array($entity, $functionName));

        return $fieldValue;
    }

    /**
     * Update the value of the entity
     * @param BusinessEntityPage $entity
     * @param string             $field
     * @param string             $value
     *
     * @return mixed
     */
    protected function setEntityAttributeValue($entity, $field, $value)
    {
        $functionName = 'set'.ucfirst($field);

        call_user_func(array($entity, $functionName), $value);
    }

    /**
     * get the view reference for a given view
     * @param View $view
     *
     * @return array
     */
    public function getViewReferenceByView(View $view, $entity = null)
    {
        $viewReference = array(
            'id'            => $this->viewCacheHelper->getViewReferenceId($view, $entity),
            'locale'        => $view->getLocale(),
            'viewId'        => $view->getId(),
            'name'          => $view->getName(),
            'viewNamespace' => $this->em->getClassMetadata(get_class($view))->name,
        );

        if ($entity) {
            $viewReference['entityId'] = $entity->getId();
            $viewReference['entityNamespace'] = $this->em->getClassMetadata(get_class($entity))->name;
        }

        if (method_exists($view, 'getUrl')) {
            $viewReference['url'] = $view->getUrl();
        }

        return $viewReference;
    }
    /**
     * compute the viewReference relative to a View + entity
     * @param View                $view
     * @param BusinessEntity|null $entity
     *
     * @return array
     */
    public function buildViewReference(View $view, $entity = null)
    {
        $viewsReferences = array();
        // if page is a pattern, compute it's bep
        if ($view instanceof BusinessEntityPagePattern) {
            if ($entity && $this->businessEntityPageHelper->isEntityAllowed($view, $entity)) {
                $currentPattern = clone $view;
                $page = $this->businessEntityPageHelper->generateEntityPageFromPattern($currentPattern, $entity);
                $this->updatePageParametersByEntity($page, $entity);
                $referenceId = $this->viewCacheHelper->getViewReferenceId($view, $entity);
                $viewsReferences[$page->getUrl().$page->getLocale()] = array(
                    'id'              => $referenceId,
                    'url'             => $page->getUrl(),
                    'name'            => $page->getName(),
                    'locale'          => $page->getLocale(),
                    'patternId'       => $page->getTemplate()->getId(),
                    'entityId'        => $entity->getId(),
                    'entityNamespace' => $this->em->getClassMetadata(get_class($entity))->name,
                    'viewNamespace'   => $this->em->getClassMetadata(get_class($view))->name,
                );
            } else {
                $referenceId = $this->viewCacheHelper->getViewReferenceId($view);
                $viewsReferences[$view->getUrl().$view->getLocale()] = array(
                    'id'              => $referenceId,
                    'url'             => $view->getUrl(),
                    'name'            => $view->getName(),
                    'locale'          => $view->getLocale(),
                    'viewId'          => $view->getId(),
                    'viewNamespace'   => $this->em->getClassMetadata(get_class($view))->name,
                );
                $businessEntities = $this->businessEntityHelper->getBusinessEntities();

                foreach ($businessEntities as $businessEntity) {
                    $properties = $this->businessEntityPageHelper->getBusinessProperties($businessEntity);

                    //find business identifiers of the current businessEntity
                    $selectableProperties = array('id');
                    foreach ($properties as $property) {
                        if ($property->getType() === 'businessParameter') {
                            $selectableProperties[] = $property->getEntityProperty();
                        }
                    }

                    $entities = $this->businessEntityPageHelper->getEntitiesAllowed($view);

                    // for each business entity
                    foreach ($entities as $entity) {
                        // only if related pattern entity is the current entity
                        if ($view->getBusinessEntityName() === $businessEntity->getId()) {
                            $currentPattern = clone $view;
                            $page = $this->businessEntityPageHelper->generateEntityPageFromPattern($currentPattern, $entity);
                            $this->updatePageParametersByEntity($page, $entity);
                            $referenceId = $this->viewCacheHelper->getViewReferenceId($view, $entity);
                            $viewsReferences[$page->getUrl().$view->getLocale()] = array(
                                'id'              => $referenceId,
                                'url'             => $page->getUrl(),
                                'name'             => $page->getName(),
                                'locale'          => $page->getLocale(),
                                'patternId'       => $page->getTemplate()->getId(),
                                'entityId'        => $entity->getId(),
                                'entityNamespace' => $this->em->getClassMetadata(get_class($entity))->name,
                                'viewNamespace'   => $this->em->getClassMetadata(get_class($view))->name,
                            );
                        }
                        //I refresh this partial entity from em. If I don't do it, everytime I'll request this entity from em it'll be partially populated
                        $this->em->refresh($entity);
                    }
                }
            }
        } elseif ($view instanceof BusinessEntityPage) {
            $referenceId = $this->viewCacheHelper->getViewReferenceId($view);
            $viewsReferences[$view->getUrl().$view->getLocale()] = array(
                'id'              => $referenceId,
                'locale'          => $view->getLocale(),
                'viewId'          => $view->getId(),
                'patternId'       => $view->getTemplate()->getId(),
                'url'             => $view->getUrl(),
                'name'            => $view->getName(),
                'entityId'        => $view->getBusinessEntity()->getId(),
                'entityNamespace' => $this->em->getClassMetadata(get_class($view->getBusinessEntity()))->name,
                'viewNamespace'   => $this->em->getClassMetadata(get_class($view))->name,
            );
        } elseif ($view instanceof Template) {
            $referenceId = $this->viewCacheHelper->getViewReferenceId($view);
            $viewsReferences[$referenceId.$view->getLocale()] = array(
                'id'              => $referenceId,
                'locale'          => $view->getLocale(),
                'name'            => $view->getName(),
                'viewId'          => $view->getId(),
                'viewNamespace'   => $this->em->getClassMetadata(get_class($view))->name,
            );
        } elseif ($view instanceof ErrorPage) {
            $referenceId = $this->viewCacheHelper->getViewReferenceId($view);
            $viewsReferences[$referenceId.$view->getLocale()] = array(
                'id'              => $referenceId,
                'locale'          => $view->getLocale(),
                'name'            => $view->getName(),
                'viewId'          => $view->getId(),
                'viewNamespace'   => $this->em->getClassMetadata(get_class($view))->name,
            );
        } elseif (method_exists($view, 'getUrl')) {
            $referenceId = $this->viewCacheHelper->getViewReferenceId($view);
            $viewsReferences[$view->getUrl().$view->getLocale()] = array(
                'id'              => $referenceId,
                'locale'          => $view->getLocale(),
                'viewId'          => $view->getId(),
                'url'             => $view->getUrl(),
                'name'            => $view->getName(),
                'viewNamespace'   => $this->em->getClassMetadata(get_class($view))->name,
            );
        }

        return $viewsReferences;
    }

    /**
     * @param View $view, the view to translatate
     * @param $templatename the new name of the view
     * @param $loopindex the current loop of iteration in recursion
     * @param $locale the target locale to translate view
     *
     * this methods allow you to add a translation to any view
     * recursively to its subview
     */
    public function addTranslation(View $view, $viewName = null, $locale)
    {
        $template = null;
        if ($view->getTemplate()) {
            $template = $view->getTemplate();
            if ($template->getI18n()->getTranslation($locale)) {
                $template = $template->getI18n()->getTranslation($locale);
            } else {
                $templateName = $template->getName()."-".$locale;
                $this->em->refresh($view);
                $template = $this->addTranslation($template, $templateName, $locale);
            }
        }
        $view->setLocale($locale);
        $view->setTemplate($template);
        $clonedView = $this->cloneView($view, $viewName, $locale);
        if ($clonedView instanceof BasePage && $view->getTemplate()) {
            $template->addPage($clonedView);
        }
        $i18n = $view->getI18n();
        $i18n->setTranslation($locale, $clonedView);
        $this->em->persist($clonedView);
        $this->em->refresh($view);
        $this->em->flush();

        return $clonedView;
    }

    /**
     * @param View $view
     * @param $etmplateName the future name of the clone
     *
     * this methods allows you to clone a view and its widgets and also the widgetmap
     *
     */
    public function cloneView(View $view, $templateName = null)
    {
        $clonedView = clone $view;
        $this->em->refresh($view);
        $widgetMapClone = $clonedView->getWidgetMap(false);
        $arrayMapOfWidgetMap = array();
        if (null !== $templateName) {
            $clonedView->setName($templateName);
        }

        $clonedView->setId(null);
        $this->em->persist($clonedView);

        if ($clonedView instanceof BusinessEntityPagePattern) {
            $clonedView = $this->cloneBusinessEntityPagePattern($clonedView);
        } else {
            $widgetLayoutSlots = [];
            $newWidgets = [];
            foreach ($clonedView->getWidgets() as $widgetKey => $widgetVal) {
                $clonedWidget = clone $widgetVal;
                $clonedWidget->setId(null);
                $clonedWidget->setView($clonedView);
                $this->em->persist($clonedWidget);
                $newWidgets[] = $clonedWidget;
                $arrayMapOfWidgetMap[$widgetVal->getId()] = $clonedWidget;
                if ($widgetVal instanceof WidgetLayout) {
                    $id = $widgetVal->getId();
                    $widgetLayoutSlots[$id] = $clonedWidget;
                }
            }
            $clonedView->setWidgets($newWidgets);
            $this->em->persist($clonedView);
            $this->em->flush();
            $widgetSlotMap = [];
            foreach ($widgetLayoutSlots as $_id => $_widget) {
                foreach ($clonedView->getWidgets() as $_clonedWidget) {
                    if (preg_match('/^'.$_id.'_(.)/', $_clonedWidget->getSlot(), $matches)) {
                        $newSlot = $_widget->getId().'_'.$matches[1];
                        $oldSlot = $_clonedWidget->getSlot();
                        $_clonedWidget->setSlot($newSlot);
                        $widgetSlotMap[$oldSlot] = $newSlot;
                    }
                }
            }

            $this->em->flush();
            foreach ($widgetMapClone as $wigetSlotCloneKey => $widgetSlotCloneVal) {
                foreach ($widgetSlotCloneVal as $widgetMapItemKey => $widgetMapItemVal) {
                    if (isset($arrayMapOfWidgetMap[$widgetMapItemVal['widgetId']])) {
                        $widgetId = $arrayMapOfWidgetMap[$widgetMapItemVal['widgetId']]->getId();
                        $widgetMapItemVal['widgetId'] = $widgetId;
                        if (array_key_exists($wigetSlotCloneKey, $widgetSlotMap)) {
                            $wigetSlotCloneKey = $widgetSlotMap[$wigetSlotCloneKey];
                        }
                        $widgetMapClone[$wigetSlotCloneKey][$widgetMapItemKey] = $widgetMapItemVal;
                    }
                }
            }

            $clonedView->setSlots(array());
            $clonedView->setWidgetMap($widgetMapClone);
            $this->em->persist($clonedView);
            $this->em->flush();
        }

        return $clonedView;
    }

    /**
     * @param BusinessEntityPagePattern $view
     * @param $etmplateName the future name of the clone
     *
     * this methods allows you to clone a BusinessEntityPagePattern
     *
     */
    protected function cloneBusinessEntityPagePattern(BusinessEntityPagePattern $view)
    {
        $businessEntityId = $view->getBusinessEntityName();
        $businessEntity = $this->get('victoire_core.helper.business_entity_helper')->findById($businessEntityId);
        $businessProperties = $businessEntity->getBusinessPropertiesByType('seoable');
    }

}
