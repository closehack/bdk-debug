<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2021 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug\Framework;

use bdk\Debug;
use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Collector\Pdo;
use bdk\Debug\Psr15\Middleware;
use bdk\ErrorHandler\Error;
use bdk\PubSub\Event;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventList;
use Cake\Http\MiddlewareQueue;
use Exception;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Cake Plugin
 *
 * In your Application.php's  bootstrap method add
 * `$app->addPlugin(new \bdk\Debug\Framework\Cake4());`
 *
 * In your app.php (and/or app_local.php)  (ie config file) add
 *   'PHPDebugConsole' => array(
 *       // phpdebug console config vals
 *   )
 */
class Cake4 extends BasePlugin
{

    private $debug;
    private $ignoreLogError = false;
    /** @var @var ErrorHandlerMiddleware */
    private $errorHandlerMiddleW;
    private $app;

    /**
     * The name of this plugin
     *
     * @var string
     */
    protected $name = 'PHPDebugConsole';

    /**
     * {@inheritDoc}
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {

        $this->app = $app;

        // Add constants, load configuration defaults.
        // By default will load `config/bootstrap.php` in the plugin.
        parent::bootstrap($app);

        // necessary to capture dispatched events
        $app->getEventManager()->setEventList(new EventList());
    }

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        $config = \array_merge(array(
            'errorHandler' => array(
                'continueToPrevHandler' => false,
                'onError' => function (Error $error) {
                    if ($error->isFatal()) {
                        // this includes Exceptions
                        $error['continueToPrevHandler'] = true;
                    }
                    if ($error['exception']) {
                        $error['continueToPrevHandler'] = false;
                        $this->ignoreLogError = true;
                    }
                }
            ),
            'onMiddleware' => function (Event $event) {
                $this->logEvents();
                if (\preg_match('#^/debug[-_]kit#', $event['request']->getPath())) {
                    // Don't output if request is debug-kit or its assets
                    $event->getSubject()->setCfg('output', false);
                }
            },
        ), Configure::read('PHPDebugConsole', array()));
        $this->debug = new Debug($config);

        $this->addLogRoute();

        /*
            Decorate PDO with our query logger
        */
        $dbDriver = \Cake\Datasource\ConnectionManager::get('default')->getDriver();
        if (!$dbDriver->isConnected()) {
            $dbDriver->connect();
        }
        $pdo = $dbDriver->getConnection();
        $dbDriver->setConnection(new Pdo($pdo));
    }

    /**
     * {@inheritDoc}
     */
    public function middleware(MiddlewareQueue $middleware): MiddlewareQueue
    {
        /*
            Insert our middleware to output PHPDebugConsole
        */
        $middleware->insertAfter(ErrorHandlerMiddleware::class, new Middleware($this->debug, array(
            'catchException' => true,   // we'll catch it, pass to PHPDebugConsole's errorHandler and passit on to Cake's handler
            'onCaughtException' => function (Exception $e, ServerRequestInterface $request) {
                return $this->errorHandlerMiddleW->handleException($e, $request);
            }
        )));
        $mwRef = new \ReflectionObject($middleware);
        $queueRef = $mwRef->getProperty('queue');
        $queueRef->setAccessible('true');
        $queue = $queueRef->getValue($middleware);
        foreach ($queue as $obj) {
            if ($obj instanceof ErrorHandlerMiddleware) {
                $this->errorHandlerMiddleW = $obj;
            }
        }
        return $middleware;
    }

    /**
     * Log dispatched events
     *
     * @return void
     */
    protected function logEvents()
    {
        $channelOptions = array(
            'channelIcon' => 'fa fa-bell-o',
            'nested' => false,
        );
        $debug = $this->debug->getChannel('Events', $channelOptions);

        $eventList = $this->app->getEventManager()->getEventList();
        $count = \count($eventList);
        $events = array();
        for ($i = 0; $i < $count; $i++) {
            $event = $eventList[$i];
            $name = $event->getName();
            if (!isset($events[$name])) {
                $events[$name] = array(
                    'name' => $name,
                    'subject' => $debug->abstracter->crateWithVals(
                        \get_class($event->getSubject()),
                        array(
                            'typeMore' => Abstracter::TYPE_STRING_CLASSNAME,
                        )
                    ),
                    'count' => 0
                );
            }
            $events[$name]['count'] ++;
        }
        \ksort($events);
        $events = \array_values($events);

        $debug->table('dispatched events', $events);
    }

    /**
     * Add a Cake Logger
     *
     * @return void
     */
    protected function addLogRoute()
    {
        $this->debug->backtrace->addInternalClass('Cake\\Log\\Log');
        \Cake\Log\Log::setConfig('PHPDebugConsole', function () {
            $logChannel = $this->debug->getChannel('log');
            $logChannel->eventManager->subscribe(Debug::EVENT_LOG, function ($event) {
                /*
                    Toss scope / entire context if empty
                */
                if (empty($event['args'][1]['scope'])) {
                    unset($event['args'][1]['scope']);
                }
                if ($this->ignoreLogError && $event['method'] === 'error') {
                    $event['appendLog'] = false;
                    $event->stopPropagation();
                    $this->ignoreLogError = false;
                }
            });
            return $logChannel->logger;
        });
    }
}
