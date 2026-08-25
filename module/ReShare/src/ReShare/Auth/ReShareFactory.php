<?php

namespace ReShare\Auth;

class ReShareFactory implements \Laminas\ServiceManager\Factory\FactoryInterface
{
    /**
     * Create an object
     *
     * @param ContainerInterface $container     Service manager
     * @param string             $requestedName Service being created
     * @param null|array         $options       Extra options (optional)
     *
     * @return object
     *
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     * creating a service.
     * @throws ContainerException&\Throwable if any other error occurs
     */
    public function __invoke(\Psr\Container\ContainerInterface $container, $requestedName, ?array $options = null)
    {
        // Try 10.2.1+ approach first
        if ($container->has(\VuFind\Db\Service\PluginManager::class)) {
            $dbServiceManager = $container->get(\VuFind\Db\Service\PluginManager::class);
            $userService = $dbServiceManager->get(\VuFind\Db\Service\UserServiceInterface::class);
        } else {
            // Fallback for 9.1.1
            $userService = $container->get(\VuFind\Db\Service\UserService::class);
        }
        $ilsAuthenticator = $container->get(\VuFind\Auth\ILSAuthenticator::class);
        $reShareConfig = $container
            ->get(\VuFind\Config\ConfigManagerInterface::class)
            ->getConfigArray('ReShare');
        return new $requestedName($userService, $ilsAuthenticator, $reShareConfig);
    }
}
