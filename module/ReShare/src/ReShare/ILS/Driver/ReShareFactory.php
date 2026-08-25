<?php

namespace ReShare\ILS\Driver;

use Laminas\Session\Container as SessionContainer;
use Laminas\Session\SessionManager;
use Psr\Container\ContainerInterface;
use VuFind\Db\Service\PluginManager as DbServiceManager;
use VuFind\Db\Service\UserServiceInterface;
use VuFind\ILS\Driver\DriverWithDateConverterFactory;

class ReShareFactory extends DriverWithDateConverterFactory
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
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ) {
        if (!empty($options)) {
            throw new \Exception('Unexpected options passed to factory.');
        }
        $sessionFactory = function ($namespace) use ($container) {
            $manager = $container->get(SessionManager::class);
            return new SessionContainer("ReShare_$namespace", $manager);
        };
        $userService = $container->get(DbServiceManager::class)
            ->get(UserServiceInterface::class);
        return parent::__invoke(
            $container,
            $requestedName,
            [$sessionFactory, $userService]
        );
    }
}
