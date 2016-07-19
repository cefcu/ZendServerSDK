<?php
namespace Client;

use Zend\ModuleManager\Feature\ConsoleBannerProviderInterface;
use Zend\Console\Adapter\AdapterInterface as Console;
use Zend\Mvc\MvcEvent;
use Zend\Config\Reader\Ini as ConfigReader;
use Zend\Http\Response as HttpResponse;

class Module implements ConsoleBannerProviderInterface
{
    /**
     * (non-PHPdoc)
     *
     * @see \Zend\ModuleManager\Feature\ConfigProviderInterface::getConfig()
     */
    public function getConfig()
    {
        $config = include __DIR__ . '/config/module.config.php';
        if (!getenv('DEBUG')) {
            $config['view_manager']['exception_message'] = <<<EOT
======================================================================
   The application has thrown an exception!
======================================================================
 :className
 :message

EOT;
        }

        return $config;
    }

    /**
     * (non-PHPdoc)
     *
     * @see \Zend\ModuleManager\Feature\AutoloaderProviderInterface::getAutoloaderConfig()
     */
    public function getAutoloaderConfig()
    {
        return array(
                'Zend\Loader\ClassMapAutoloader' => array(
                    __DIR__ . '/autoload_classmap.php',
                ),
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                          __NAMESPACE__ => __DIR__ . '/src/'.__NAMESPACE__
                    )
                )
        );
    }

    /**
     *
     * @param MvcEvent $e
     */
    public function onBootstrap($event)
    {
        $eventManager = $event->getApplication()->getEventManager();
        $eventManager->attach(MvcEvent::EVENT_ROUTE,
                array(
                        $this,
                        'postRoute'
                ), - 2);

        $eventManager->attach(MvcEvent::EVENT_FINISH,
                 array(
                         $this,
                         'preFinish'
                 ), 100);
    }

    /**
     * Manage special input parameters (arrays and files) and target
     *
     * @param MvcEvent $event
     */
    public function postRoute(MvcEvent $event)
    {
        $match = $event->getRouteMatch();
        if (! $match) {
            return;
        }
        $services = $event->getApplication()->getServiceManager();

        // [Normalize the arguments]
        $config = $services->get('config');
        $routeName = $match->getMatchedRouteName();
        if (isset(
                $config['console']['router']['routes'][$routeName]['options']['arrays'])) {
            foreach ($config['console']['router']['routes'][$routeName]['options']['arrays'] as $arrayParam) {
                if ($value = $match->getParam($arrayParam)) {
                    $data = array();

                    // if the data ends with <char(s)> then we have special delimiter
                    $delimiter = '&'; // Ex: "x[1]=2&y[b]=z"
                    if (strpos($value, '=')===false) {
                        // when we do not have pairs the delimiter in such situations
                        // is set to "comma" by default
                        $delimiter = ','; // Ex: "x,y,z"
                    }
                    if (preg_match("/<(.*?)>$/", $value, $m)) { // Ex: "x[1]=2|y[b]=z<|>" - the delimiter is | and the pairs are x[1]=2 and y[b]=z
                        $delimiter = $m[1];
                        $value = substr($value, 0, strlen($value)-strlen("<$delimiter>"));
                    }

                    if (strpos($value, '=')===false) {
                        // we do not have pairs
                        $data = explode($delimiter, $value);
                        foreach ($data as $i=>$v) {
                            $data[$i] = trim($v);
                        }
                    } else {
                        // Ex: x=1 y[1]=2 y[2]=3
                        Utils::parseString($value, $data, $delimiter);
                    }

                    $match->setParam($arrayParam, $data);
                }
            }
        }

        // [Translate all paths to real absolute paths]
        if (isset(
                $config['console']['router']['routes'][$routeName]['options']['files'])
        ) {
            $path = $services->get('path');
            foreach ($config['console']['router']['routes'][$routeName]['options']['files'] as $param) {
                if ($value = $match->getParam($param)) {
                    if (!is_array($value)) {
                        $match->setParam($param, $path->getAbsolute($value));
                    } else {
                        $newValue = array_map(function ($v) use ($path) {
                            return $path->getAbsolute($v);
                        }, $value);
                        $match->setParam($param, $newValue);
                    }
                }
            }
        }

        // [Figure out the target]
        if (isset($config['console']['router']['routes'][$routeName]['options']['no-target'])) {
            return;
        }
        // Read the default target
        $targetConfig = $services->get('targetConfig');

        // Add manage named target (defined in zsapi.ini)
        $target = $match->getParam('target');
        if ($target) {
            try {
                $reader = new ConfigReader();
                $data = $reader->fromFile($config['zsapi']['file']);
                if (empty($data[$target])) {
                    if (!isset($config['console']['router']['routes'][$routeName]['options']['ingore-target-load'])) {
                        throw new \Zend\Console\Exception\RuntimeException('Invalid target specified.');
                    } else {
                        $data[$target] = array();
                    }
                }
                foreach ($data[$target] as $k=>$v) {
                    $targetConfig[$k] = $v;
                }
            } catch (\Zend\Config\Exception\RuntimeException $ex) {
                if (!isset($config['console']['router']['routes'][$routeName]['options']['ingore-target-load'])) {
                    throw new \Zend\Console\Exception\RuntimeException(
                        'Make sure that you have set your target first. \n
                                                                This can be done with ' .
                        __FILE__ .
                        ' addTarget --target=<UniqueName> --zsurl=http://localhost:10081/ZendServer --zskey= --zssecret=');
                }
            }
        }

        if (empty($targetConfig) &&
            ! ($match->getParam('zskey') || $match->getParam('zssecret') || $match->getParam('zsurl'))
        ) {
            throw new \Zend\Console\Exception\RuntimeException(
                    'Specify either a --target= parameter or --zsurl=http://localhost:10081/ZendServer --zskey= --zssecret=');
        }

        // optional: override the target parameters from the command line
        foreach (array(
                    'zsurl',
                    'zskey',
                    'zssecret',
                    'zsversion',
                    'http'
        ) as $key) {
            if (! $match->getParam($key)) {
                continue;
            }
            $targetConfig[$key] = $match->getParam($key);
        }
        
        // Check if there is a port set in the URL and if not set a default one
        $url = parse_url($targetConfig['zsurl']);
        if (!isset($url['port'])) {
            if (strtolower($url['scheme']) == "https") {
                $url['port'] = 10082;
            } else {
                $url['port'] = 10081;
            }
        
            error_log(sprintf("NOTICE: zsurl parameter does not specify port. Using default port %d.",
                $url['port']));
        
            $targetConfig['zsurl'] = sprintf("%s://%s:%s%s%",
                $url['scheme'], $url['host'], $url['port'], $url['path'],
                (isset($url['query'])? '?'.$url['query']: ''));
        }

        $outputFormat = $match->getParam('output-format', 'xml');
        if ($outputFormat =='kv') {
            $outputFormat = "json";
        }
        $apiManager = $services->get('zend_server_api');
        $apiManager->setOutputFormat($outputFormat);
    }

    /**
     *
     * @param MvcEvent $event
     */
    public function preFinish(MvcEvent $event)
    {
        $response = $event->getResponse();
        if ($response instanceof HttpResponse) {
            $response->setContent($response->getBody());
        }

        $match = $event->getRouteMatch();
        if ($match) {
            $outputFormat = $match->getParam('output-format');
            if ($outputFormat != "kv") {
                return;
            }

            $output = "";
            $content = $response->getContent();
            $data = json_decode($content, true);
            if (isset($data['responseData'])) {
                $output = Utils::array2KV($data['responseData']);
            }

            $response->setContent($output);
        }
    }

    /**
     * (non-PHPdoc)
     *
     * @see \Zend\ModuleManager\Feature\ConsoleBannerProviderInterface::getConsoleBanner()
     */
    public function getConsoleBanner(Console $console)
    {
        return 'Zend Server Client v1.0';
    }
}
