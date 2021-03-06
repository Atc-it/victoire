<?php
namespace Victoire\Bundle\PageBundle\Helper;

use Doctrine\Orm\EntityManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Victoire\Bundle\BusinessEntityPageBundle\Entity\BusinessEntityPage;
use Victoire\Bundle\BusinessEntityPageBundle\Entity\BusinessEntityPagePattern;
use Victoire\Bundle\BusinessEntityPageBundle\Helper\BusinessEntityPageHelper;
use Victoire\Bundle\CoreBundle\Entity\EntityProxy;
use Victoire\Bundle\CoreBundle\Entity\View;
use Victoire\Bundle\CoreBundle\Helper\CurrentViewHelper;
use Victoire\Bundle\CoreBundle\Helper\ViewCacheHelper;
use Victoire\Bundle\CoreBundle\Helper\ViewHelper;
use Victoire\Bundle\CoreBundle\Template\TemplateMapper;
use Victoire\Bundle\PageBundle\Entity\BasePage;
use Victoire\Bundle\PageBundle\Entity\Page;
use Victoire\Bundle\SeoBundle\Helper\PageSeoHelper;
use Victoire\Bundle\WidgetMapBundle\Builder\WidgetMapBuilder;
use Victoire\Bundle\BusinessEntityBundle\Converter\ParameterConverter as BETParameterConverter;
use Victoire\Bundle\BusinessEntityBundle\Helper\BusinessEntityHelper;
/**
 * Page helper
 * ref: victoire_page.page_helper
 */
class PageHelper extends ViewHelper
{
    protected $bepHelper = null;
    protected $entityManager; // @doctrine.orm.entity_manager'
    protected $currentViewHelper; // @victoire_core.current_view
    protected $eventDispatcher; // @event_dispatcher
    protected $victoireTemplating; // @victoire_templating
    protected $pageSeoHelper; // @victoire_seo.helper.pageseo_helper
    protected $session; // @session
    protected $token_storage; // @security.authorization_checker
    protected $authorizationChecker; // @security.authorization_checker
    protected $widgetMapBuilder; // @victoire_widget_map.builder
    public $viewCacheHelper; // @victoire_core.view_cache_helper

    //@todo Make it dynamic please
    protected $pageParameters = array(
        'name',
        'bodyId',
        'bodyClass',
        'slug',
        'url',
    );

    /**
     * Constructor
     * @param BusinessEntityPageHelper $bepHelper
     * @param EntityManager            $entityManager
     * @param CurrentViewHelper        $currentViewHelper
     * @param EventDispatcherInterface $eventDispatcher
     * @param TemplateMapper           $victoireTemplating
     * @param PageSeoHelper            $pageSeoHelper
     * @param ViewCacheHelper          $viewCacheHelper
     * @param Session                  $session
     * @param TokenStorage             $tokenStorage
     * @param AuthorizationChecker     $authorizationChecker
     * @param WidgetMapBuilder         $widgetMapBuilder
     */
    public function __construct(
        BusinessEntityPageHelper $bepHelper,
        EntityManager $entityManager,
        CurrentViewHelper $currentViewHelper,
        EventDispatcherInterface $eventDispatcher,
        TemplateMapper $victoireTemplating,
        PageSeoHelper $pageSeoHelper,
        ViewCacheHelper $viewCacheHelper,
        Session $session,
        TokenStorage $tokenStorage,
        AuthorizationChecker $authorization_checker,
        WidgetMapBuilder $widgetMapBuilder,
        BETParameterConverter $parameterConverter,
        BusinessEntityHelper $businessEntityHelper,
        ViewCacheHelper $viewCacheHelper
    ) {
        parent::__construct($parameterConverter, $businessEntityHelper, $bepHelper, $entityManager, $viewCacheHelper);
        $this->bepHelper = $bepHelper;
        $this->entityManager = $entityManager;
        $this->currentViewHelper = $currentViewHelper;
        $this->eventDispatcher = $eventDispatcher;
        $this->victoireTemplating = $victoireTemplating;
        $this->pageSeoHelper = $pageSeoHelper;
        $this->viewCacheHelper = $viewCacheHelper;
        $this->session = $session;
        $this->tokenStorage = $tokenStorage;
        $this->authorizationChecker = $authorization_checker;
        $this->widgetMapBuilder = $widgetMapBuilder;
    }

    /**
     * generates a response from a page url
     *
     * @return View
     */
    public function findPageByParameters($parameters)
    {
        $viewReference = $this->viewCacheHelper->getReferenceByParameters($parameters);
        if ($viewReference === null && !empty($parameters['viewId'])) {
            $parameters['patternId'] = $parameters['viewId'];
            unset($parameters['viewId']);
            $viewReference = $this->viewCacheHelper->getReferenceByParameters($parameters);
        }
        $page = $this->findPageByReference($viewReference);

        return $page;
    }

    /**
     * generates a response from a page url
     * @param string $url
     *
     * @return Response
     */
    public function renderPageByUrl($url, $locale)
    {
        $page = $this->findPageByParameters(array('url' => $url, 'locale' => $locale));

        $event = new \Victoire\Bundle\PageBundle\Event\Menu\PageMenuContextualEvent($page);

        //Dispatch contextual event regarding page type
        if ($page->getType() == 'business_entity_page') {
            //Dispatch also an event with the Business entity name
            $eventName = 'victoire_core.page_menu.contextual';
            if (!$page->getId()) {
                $eventName = 'victoire_core.business_entity_page_pattern_menu.contextual';
                $event = new \Victoire\Bundle\PageBundle\Event\Menu\PageMenuContextualEvent($page->getTemplate());
            }
            $this->eventDispatcher->dispatch($eventName, $event);
            $type = strtolower($page->getBusinessEntityName());
        } else {
            $type = $page->getType();
        }

        $eventName = 'victoire_core.'.$type.'_menu.contextual';
        $this->eventDispatcher->dispatch($eventName, $event);

        $layout = 'AppBundle:Layout:'.$page->getTemplate()->getLayout().'.html.twig';

        $this->widgetMapBuilder->build($page);
        $this->currentViewHelper->setCurrentView($page);
        //create the response
        $response = $this->victoireTemplating->renderResponse($layout, array(
            "view" => $page,
        ));

        return $response;
    }

    /**
     * populate the page with given entity
     * @param View           $page
     * @param BusinessEntity $entity
     *
     * @return BusinessEntityPage
     */
    public function updatePageWithEntity(BusinessEntityPagePattern $page, $entity)
    {
        $page = $this->bepHelper->generateEntityPageFromPattern($page, $entity);
        $this->pageSeoHelper->updateSeoByEntity($page, $entity);

        //update the parameters of the page
        $this->updatePageParametersByEntity($page, $entity);

        $businessEntity = $this->businessEntityHelper->findByEntityInstance($entity);
        $entityProxy = new EntityProxy();
        $entityProxy->setEntity($entity, $businessEntity->getName());
        $page->setEntityProxy($entityProxy);

        return $page;
    }

    /**
     * read the cache to find entity according tu given url
     * @param array $viewReference
     *
     * @return BusinessEntity|null
     */
    protected function findEntityByReference($viewReference)
    {
        $entity = null;
        if (!empty($viewReference['entityId'])) {
            $entity = $this->entityManager->getRepository($viewReference['entityNamespace'])->findOneById($viewReference['entityId']);
        }

        return $entity;
    }

    /**
     * Search a page in the route history according to giver url
     * @param string $url
     *
     * @return BasePage|null
     */
    public function findPageInRouteHistory($url)
    {
        $route = $this->entityManager->getRepository('VictoireCoreBundle:Route')->findOneMostRecentByUrl($url);
        if ($route !== null) {
            //the page linked to the old url
            return $route->getPage();
        }

        return;
    }

    /**
     * find the page according to given url. If not found, try in route history, if seo redirect, return target
     *
     * @return View
     */
    public function findPageByReference($viewReference)
    {
        $page = null;
        //get the page
        if (!empty($viewReference['viewId'])) {
            $page = $this->entityManager->getRepository('VictoireCoreBundle:View')->findOneById($viewReference['viewId']);
        } elseif (!empty($viewReference['patternId'])) {
            $page = $this->entityManager->getRepository('VictoireCoreBundle:View')->findOneById($viewReference['patternId']);
        }

        if (!$page) {
            $page = $this->findPageInRouteHistory($viewReference['url']);
        }

        if ($page instanceof BasePage
            && $page->getSeo()
            && $page->getSeo()->getRedirectTo()
            && !$this->session->get('victoire.edit_mode', false)) {
            $page = $page->getSeo()->getRedirectTo();
        }

        if ($viewReference && $page instanceof View) {
            $page->setReference($viewReference);
        }

        $entity = $this->findEntityByReference($viewReference);
        if ($entity && $page instanceof BusinessEntityPagePattern) {
            $page = $this->updatePageWithEntity($page, $entity);
        }
        $this->checkPageValidity($page, $entity);

        return $page;
    }

    /**
     * If the valid is not valid, an exception is thrown
     * @param Page   $page
     * @param Entity $entity
     *
     * @throws NotFoundHttpException
     * @todo  REFACTOR
     */
    protected function checkPageValidity($page, $entity = null)
    {
        $errorMessage = 'The page was not found.';
        $isPageOwner = false;

        //there is no page
        if ($page === null) {
            throw new NotFoundHttpException($errorMessage);
        }

        if ($this->tokenStorage->getToken()) {
            $isPageOwner = $this->authorizationChecker->isGranted('PAGE_OWNER', $page);
        }

        //a page not published, not owned, nor granted throw an exception
        if (($page instanceof BasePage && !$page->isPublished()) && !$isPageOwner) {
            throw new NotFoundHttpException($errorMessage);
        }

        //if the page is a BusinessEntityPagePattern and the entity is not allowed for this page pattern
        if ($page instanceof BusinessEntityPagePattern) {
            //only victoire users are able to access a business page
            if (!$this->authorizationChecker->isGranted('ROLE_VICTOIRE')) {
                throw new AccessDeniedException('You are not allowed to see this page');
            }
        } elseif ($page instanceof BusinessEntityPage) {
            if (!$entity->isVisibleOnFront() && !$this->authorizationChecker->isGranted('ROLE_VICTOIRE')) {
                throw new NotFoundHttpException('The BusinessEntityPage for '.get_class($entity).'#'.$entity->getId().' is not visible on front.');
            }
            if (!$page->getId()) {
                $entityAllowed = $this->bepHelper->isEntityAllowed($page->getTemplate(), $entity);

                if ($entityAllowed === false) {
                    throw new NotFoundHttpException('The entity ['.$entity->getId().'] is not allowed for the page pattern ['.$page->getTemplate()->getId().']');
                }
            }
        }
    }

    /**
     * Create an instance of the business entity page
     * @param BusinessEntityPagePattern $businessEntityPagePattern The business entity page
     * @param entity                    $entity                    The entity
     * @param string                    $url                       The new url
     *
     * @return \Victoire\Bundle\PageBundle\Entity\Page
     */
    public function createPageInstanceFromBusinessEntityPagePattern(BusinessEntityPagePattern $businessEntityPagePattern, $entity, $url)
    {
        //create a new page
        $newPage = new Page();

        $parentPage = $businessEntityPagePattern->getParent();

        //set the page parameter by the business entity page
        $newPage->setParent($parentPage);
        $newPage->setTemplate($businessEntityPagePattern);
        $newPage->setUrl($url);

        $newPage->setTitle($businessEntityPagePattern->getTitle());

        //update the parameters of the page
        $this->updatePageParametersByEntity($newPage, $entity);

        $businessEntity = $this->businessEntityHelper->findByEntityInstance($entity);
        $entityProxy = new EntityProxy();
        $entityProxy->setEntity($entity, $businessEntity->getName());

        $newPage->setEntityProxy($entityProxy);

        return $newPage;
    }
}
