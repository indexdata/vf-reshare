<?php

namespace ReShare\Controller;

class MyResearchController extends \VuFind\Controller\MyResearchController
{
    /**
     * Logout Action
     *
     * @return mixed
     */
    public function logoutAction()
    {
        $reShareConfig = $this->configManager->getConfigArray('ReShare');
        $authManager = $this->getAuthManager();
        $redirectUrl = $authManager->getLogoutRedirectUrl(
            $reShareConfig['Site']['logout_url']
        );
        $authManager->clearLoginState();
        return $this->redirect()->toUrl($redirectUrl);
    }

}
