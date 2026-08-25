<?php

namespace ReShare\RecordDriver;

use Interop\Container\ContainerInterface;

class IlsAwareDelegatorFactory extends \VuFind\RecordDriver\IlsAwareDelegatorFactory
{

    public function __invoke(ContainerInterface $container, $name,
        callable $callback, ?array $options = null
    ) {
        $driver = call_user_func($callback);

        // Attach the ILS if at least one backend supports it:
        $ilsBackends = $this->getIlsBackends($container);
        if (!empty($ilsBackends) && $container->has(\VuFind\ILS\Connection::class)) {
            $driver->attachILS(
                $container->get(\VuFind\ILS\Connection::class),
                $container->get(\VuFind\ILS\Logic\Holds::class),
                $container->get(\VuFind\ILS\Logic\TitleHolds::class)
            );
            $driver->setIlsBackends($ilsBackends);
        }
        $patronID = $this->getPatronID($container);
        $college = $this->getCollege($container);
        $driver->setPatronID($patronID);
        $driver->setCollege($college);
        $reShareConfig = $container
            ->get(\VuFind\Config\ConfigManagerInterface::class)
            ->getConfigArray('ReShare');
        $driver->setOpenUrlReferrerId(
            $reShareConfig['OpenURL']['referrer_id']
        );
        $driver->setNonRequestableIdPrefixes(
            (array)($reShareConfig['Requests']['non_requestable_id_prefixes'] ?? [])
        );

        return $driver;
    }

    /**
     * Get the ILS backend configuration.
     *
     * @param ContainerInterface $container Service container
     *
     * @return string[]
     */
    protected function getIlsBackends(ContainerInterface $container)
    {
        // Get a list of ILS-compatible backends.
        static $ilsBackends = null;
        if (!is_array($ilsBackends)) {
            $config = $container->get(\VuFind\Config\PluginManager::class)
                ->get('config');
            $settings = isset($config->Catalog) ? $config->Catalog->toArray() : [];

            // If the setting is missing, default to the default backend; if it
            // is present but empty, don't put an empty string in the final array!
            $rawSetting = $settings['ilsBackends'] ?? [DEFAULT_SEARCH_BACKEND];
            $ilsBackends = empty($rawSetting) ? [] : (array)$rawSetting;
        }
        return $ilsBackends;
    }

    protected function getPatronID(ContainerInterface $container)
    {
        $user = $container->get(\VuFind\Auth\Manager::class)->getUserObject();
        return $user?->getUsername();
    }

    protected function getCollege(ContainerInterface $container)
    {
        $user = $container->get(\VuFind\Auth\Manager::class)->getUserObject();
        return $user?->getCollege();
    }
}
