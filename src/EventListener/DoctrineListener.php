<?php

namespace Bolt\EventListener;

use Bolt\Config;
use Bolt\Events\FailedConnectionEvent;
use Bolt\Exception\Database\DatabaseConnectionException;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Events;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Listener for Doctrine events.
 *
 * @author Carson Full <carsonfull@gmail.com>
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class DoctrineListener implements EventSubscriber
{
    use LoggerAwareTrait;

    /** @var Config */
    private $config;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->setLogger($logger);
    }

    /**
     * Event fired on database connection failure.
     *
     * @param FailedConnectionEvent $args
     *
     * @throws DatabaseConnectionException
     */
    public function failConnect(FailedConnectionEvent $args)
    {
        $e = $args->getException();
        $this->logger->debug($e->getMessage(), ['event' => 'exception', 'exception' => $e]);

        throw new DatabaseConnectionException($args->getDriver()->getName(), $e->getMessage(), $e);
    }

    /**
     * After connecting, update this connection's database settings.
     *
     * Note: Doctrine expects this method to be called postConnect
     *
     * @param ConnectionEventArgs $args
     */
    public function postConnect(ConnectionEventArgs $args)
    {
        $db = $args->getConnection();
        $platform = $args->getDatabasePlatform()->getName();

        if ($platform === 'sqlite') {
            $db->query('PRAGMA synchronous = OFF');
        } elseif ($platform === 'mysql') {
            /**
             * @link https://groups.google.com/forum/?fromgroups=#!topic/silex-php/AR3lpouqsgs
             */
            $db->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

            // Set database character set & collation as configured
            $charset   = $this->config->get('general/database/charset');
            $collation = $this->config->get('general/database/collate');
            $db->executeQuery(sprintf('SET NAMES %s COLLATE %s', $charset, $collation));

            // Increase group_concat_max_len to 100000. By default, MySQL
            // sets this to a low value – 1024 – which causes issues with
            // certain Bolt content types – particularly repeaters – where
            // the outcome of a GROUP_CONCAT() query will be more than 1024 bytes.
            // See also: http://dev.mysql.com/doc/refman/5.7/en/server-system-variables.html#sysvar_group_concat_max_len
            $db->executeQuery('SET SESSION group_concat_max_len = 100000');
        } elseif ($platform === 'postgresql') {
            /**
             * @link https://github.com/doctrine/dbal/pull/828
             */
            $db->executeQuery("SET NAMES 'utf8'");
        }
    }

    public function getSubscribedEvents()
    {
        return [
            Events::postConnect,
            'failConnect',
        ];
    }
}
