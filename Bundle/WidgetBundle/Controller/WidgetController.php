<?php

namespace Victoire\Bundle\WidgetBundle\Controller;

use AppVentus\Awesome\ShortcutsBundle\Controller\AwesomeController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Victoire\Bundle\CoreBundle\Entity\View;
use Victoire\Bundle\CoreBundle\Widget\Managers\WidgetManager;
use Victoire\Bundle\TemplateBundle\Entity\Template as VicTemplate;
use Victoire\Bundle\WidgetBundle\Entity\Widget;

/**
 * Widget Controller
 *
 */
class WidgetController extends AwesomeController
{
    /**
     * Show a widget
     * @param Widget  $widget          the widget to show
     * @param integer $viewReferenceId The id of the view
     *
     * @return response
     * @Route("/victoire-dcms-public/widget/show/{id}/{viewReferenceId}", name="victoire_core_widget_show", options={"expose"=true})
     * @Template()
     * @ParamConverter("id", class="VictoireWidgetBundle:Widget")
     */
    public function showAction(Widget $widget, $viewReferenceId)
    {
        //the response is for the ajax.js from the AppVentus Ajax Bundle
        try {
            $view = $this->container->get('victoire_page.page_helper')->findPageByParameters(array('id' => $viewReferenceId));
            $this->container->get('victoire_core.current_view')->setCurrentView($view);
            if ($this->getRequest()->isXmlHttpRequest()) {

                 $response = new JsonResponse(array(
                         'html'    => $this->get('victoire_widget.widget_renderer')->render($widget, $view),
                         'update'  => 'vic-widget-'.$widget->getId().'-container',
                         'success' => false
                 ));
            } else {
                $response = $this->redirect($this->generateUrl('victoire_core_page_show', array('url' => $view->getUrl())));
            }
        } catch (\Exception $ex) {
            $response = $this->getJsonReponseFromException($ex);
        }

        return $response;
    }

    /**
     * New Widget
     *
     * @param string  $type          The type of the widget we edit
     * @param integer $viewReference The view reference where attach the widget
     * @param string  $slot          The slot where attach the widget
     * @param integer $position      The position in the widgetMap
     *
     * @return response
     *
     * @Route("/victoire-dcms/widget/new/{type}/{viewReference}/{slot}/{position}", name="victoire_core_widget_new", defaults={"slot":null}, options={"expose"=true})
     * @Template()
     */
    public function newAction($type, $viewReference, $slot = null, $position = 0)
    {
        try {
            $view = $this->getViewByReferenceId($viewReference);
            $response = new JsonResponse($this->get('widget_manager')->newWidget($type, $slot, $view, $position));
        } catch (\Exception $ex) {
            $response = $this->getJsonReponseFromException($ex);
        }

        return $response;
    }

    /**
     * Create a widget
     * @param string         $type          The type of the widget we edit
     * @param integer        $viewReference The view reference where attach the widget
     * @param string         $slot          The slot where attach the widget
     * @param string         $position      Position of the widget
     * @param BusinessEntity $entityName    The business entity name the widget shows on dynamic mode
     *
     * @return response
     * @Route("/victoire-dcms/widget/create/{type}/{viewReference}/{slot}/{position}/{entityName}", name="victoire_core_widget_create", defaults={"slot":null, "entityName":null, "position": 0, "_format": "json"})
     * @Template()
     */
    public function createAction($type, $viewReference, $slot = null, $position = 0, $entityName = null)
    {
        try {
            //services
            $em = $this->getEntityManager();
            $view = $this->getViewByReferenceId($viewReference);

            $isNewPage = $view->getId() == null ? true : false;

            $this->get('victoire_core.current_view')->setCurrentView($view);
            $widgetManager = $this->getWidgetManager();

            $response = $widgetManager->createWidget($type, $slot, $view, $entityName, $position);

            if ($isNewPage) {
                $response = new JsonResponse(array(
                    'success' => true,
                    'redirect' => $this->generateUrl('victoire_core_page_show', array('url' => $view->getUrl())),
                ));
            } else {
                $response = new JsonResponse($response);
            }
        } catch (\Exception $ex) {
            $response = $this->getJsonReponseFromException($ex);
        }

        return $response;
    }

    /**
     * Edit a widget
     * @param Widget  $widget        The widget to edit
     * @param integer $viewReference The current view
     * @param string  $entityName    The entity name (could be null is the submitted form is in static mode)
     *
     * @return response
     *
     * @Route("/victoire-dcms/widget/edit/{id}/{viewReference}/{entityName}", name="victoire_core_widget_edit")
     * @Route("/victoire-dcms/widget/update/{id}/{viewReference}/{entityName}", name="victoire_core_widget_update", defaults={"entityName": null})
     * @Template()
     */
    public function editAction(Widget $widget, $viewReference, $entityName = null)
    {
        $view = $this->getViewByReferenceId($viewReference);
        $widgetView = $widget->getView();

        if ($widgetView instanceof VicTemplate) {
            $widgetViewReference = $this->get('victoire_core.view_cache_helper')->getReferenceByParameters(array('viewId' => $widgetView->getId()));
        } else {
            $widgetViewReference = $this->get('victoire_core.view_cache_helper')->getReferenceByParameters(array('url' => $widgetView->getUrl()));
        }

        $widgetView->setReference($widgetViewReference);
        $this->get('victoire_core.current_view')->setCurrentView($view);
        try {
            $widgetManager = $this->getWidgetManager();
            $response = new JsonResponse($widgetManager->editWidget($this->get('request'), $widget, $view, $entityName));
        } catch (\Exception $ex) {
            $response = $this->getJsonReponseFromException($ex);
        }

        return $response;
    }

    /**
     * Delete a Widget
     * @param Widget $widget The widget to delete
     *
     * @return empty response
     * @Route("/victoire-dcms/widget/delete/{id}/{viewReference}", name="victoire_core_widget_delete", defaults={"_format": "json"})
     * @Template()
     */
    public function deleteAction(Widget $widget, $viewReference)
    {
        $view = $this->getViewByReferenceId($viewReference);
        try {
            $response = new JsonResponse($this->get('widget_manager')->deleteWidget($widget, $view));
        } catch (\Exception $ex) {
            $response = $this->getJsonReponseFromException($ex);
        }

        return $response;
    }

    /**
     * Update widget positions accross the view. If moved widget is a Reference, ask to detach the view from template
     *
     * @param View $view The view where update widget positions
     *
     * @return response
     * @Route("/victoire-dcms/widget/updatePosition/{viewReference}", name="victoire_core_widget_update_position", options={"expose"=true})
     */
    public function updatePositionAction($viewReference)
    {
        $view = $this->getViewByReferenceId($viewReference);
        try {
            //the sorted order for the widgets
            $sortedWidgets = $this->getRequest()->request->get('sorted');

            if (!$view->getId()) {
                //This view does not have an id, so it's a non persisted BEP. To keep this new order, well have to persist it.
                $this->getEntityManager()->persist($view);
                $this->getEntityManager()->flush();
            }

            //recompute the order for the widgets
            $this->get('view.widgetMap.builder')->updateWidgetMapOrder($view, $sortedWidgets);

            $response = new JsonResponse(array('success' => true));
        } catch (\Exception $ex) {
            $response = $this->getJsonReponseFromException($ex);
        }

        return $response;
    }

    /**
     * Shortcut for getting the widget manager
     *
     * @return WidgetManager
     */
    protected function getWidgetManager()
    {
        $manager = $this->get('widget_manager');

        return $manager;
    }

    /**
     * Get the json response by the exception and the current user
     *
     * @param \Exception $ex
     *
     * @return JsonResponse
     */
    protected function getJsonReponseFromException(\Exception $ex)
    {
        //services
        $securityContext = $this->get('security.context');
        $logger = $this->get('logger');

        //can we see the debug
        $isDebugAllowed = $securityContext->isGranted('ROLE_VICTOIRE_PAGE_DEBUG');

        //whatever is the exception, we log it
        $logger->error($ex->getMessage());
        $logger->error($ex->getTraceAsString());

        if ($isDebugAllowed) {
            throw $ex;
        } else {
            //translate the message
            $translator = $this->get('translator');

            //get the translated message
            $message = $translator->trans('error_occured', array(), 'victoire');

            $response = new JsonResponse(
                array(
                    'success' => false,
                    'message' => $message
                )
            );
        }

        return $response;
    }

    protected function getViewByReferenceId($referenceId)
    {
        return $this->get('victoire_page.page_helper')->findPageByParameters(array('id' => $referenceId));
    }
}
