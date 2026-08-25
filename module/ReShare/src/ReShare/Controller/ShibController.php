<?php
namespace ReShare\Controller;

class ShibController extends \VuFind\Controller\MyResearchController
{
    public function redirectAction()
    {
        $targetUrl = $this->params()->fromQuery('target');
        $entityID = $this->params()->fromQuery('entityID') ?? NULL;
        $this->followup()->store([], $targetUrl);
        $si = $this->getSessionInitiator();
	if ($entityID) {
            return $this->redirect()->toUrl($si . '&entityID=' . $entityID);
	} else {
            return $this->redirect()->toUrl($si);
	}
    }
}
