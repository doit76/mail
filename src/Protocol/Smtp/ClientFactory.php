<?php
declare(strict_types=1);

namespace Genkgo\Mail\Protocol\Smtp;

use Genkgo\Mail\Protocol\ConnectionInterface;
use Genkgo\Mail\Protocol\CryptoConstant;
use Genkgo\Mail\Protocol\PlainTcpConnection;
use Genkgo\Mail\Protocol\AutomaticConnection;
use Genkgo\Mail\Protocol\SecureConnectionOptions;
use Genkgo\Mail\Protocol\Smtp\Negotiation\AuthNegotiation;
use Genkgo\Mail\Protocol\Smtp\Negotiation\ForceTlsUpgradeNegotiation;
use Genkgo\Mail\Protocol\Smtp\Negotiation\TryTlsUpgradeNegotiation;
use Genkgo\Mail\Protocol\SslConnection;
use Genkgo\Mail\Protocol\TlsConnection;

/**
 * Class ClientFactory
 * @package Genkgo\Mail\Protocol\Smtp
 */
final class ClientFactory
{
    /**
     *
     */
    private CONST AUTH_ENUM = [Client::AUTH_NONE, Client::AUTH_PLAIN, Client::AUTH_LOGIN, Client::AUTH_AUTO];
    /**
     * @var ConnectionInterface
     */
    private $connection;
    /**
     * @var string
     */
    private $password = '';
    /**
     * @var float
     */
    private $timeout = 1;
    /**
     * @var string
     */
    private $username = '';
    /**
     * @var string
     */
    private $ehlo = '127.0.0.1';
    /**
     * @var int
     */
    private $authMethod = Client::AUTH_NONE;
    /**
     * @var bool
     */
    private $insecureConnectionAllowed = false;
    /**
     * @var string
     */
    private $reconnectAfter = 'PT300S';
    /**
     * @var int
     */
    private $crypto = CryptoConstant::TYPE_BEST_PRACTISE;

    /**
     * ClientFactory constructor.
     * @param ConnectionInterface $connection
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param float $connectionTimeout
     * @return ClientFactory
     */
    public function withTimeout(float $connectionTimeout): ClientFactory
    {
        $clone = clone $this;
        $clone->timeout = $connectionTimeout;
        return $clone;
    }

    /**
     * @param int $method
     * @param string $password
     * @param string $username
     * @return ClientFactory
     */
    public function withAuthentication(int $method, string $username, string $password): ClientFactory
    {
        if (!in_array($method, self::AUTH_ENUM)) {
            throw new \InvalidArgumentException('Invalid authentication method');
        }

        $clone = clone $this;
        $clone->authMethod = $method;
        $clone->username = $username;
        $clone->password = $password;
        return $clone;
    }

    /**
     * @param string $ehlo
     * @return ClientFactory
     */
    public function withEhlo(string $ehlo): ClientFactory
    {
        $clone = clone $this;
        $clone->ehlo = $ehlo;
        return $clone;
    }

    /**
     * @return ClientFactory
     */
    public function withInsecureConnectionAllowed(): ClientFactory
    {
        $clone = clone $this;
        $clone->insecureConnectionAllowed = true;
        return $clone;
    }

    /**
     * @param int $crypto
     * @return ClientFactory
     */
    public function withCrypto(int $crypto): ClientFactory
    {
        $clone = clone $this;
        $clone->crypto = $crypto;
        return $clone;
    }

    /**
     * @return Client
     */
    public function newClient(): Client
    {
        $negotiators = [];

        if ($this->crypto !== 0) {
            if ($this->insecureConnectionAllowed) {
                $negotiators[] = new TryTlsUpgradeNegotiation(
                    $this->connection,
                    $this->ehlo,
                    $this->crypto
                );
            } else {
                $negotiators[] = new ForceTlsUpgradeNegotiation(
                    $this->connection,
                    $this->ehlo,
                    $this->crypto
                );
            }
        }

        if ($this->authMethod !== Client::AUTH_NONE) {
            $negotiators[] = new AuthNegotiation(
                $this->ehlo,
                $this->authMethod,
                $this->username,
                $this->password
            );
        }

        return new Client(
            new AutomaticConnection(
                $this->connection,
                new \DateInterval($this->reconnectAfter)
            ),
            $negotiators
        );
    }

    /**
     * @param string $dataSourceName
     * @return ClientFactory
     */
    public static function fromString(string $dataSourceName):ClientFactory
    {
        $components = parse_url($dataSourceName);
        if (!isset($components['scheme']) || !isset($components['host'])) {
            throw new \InvalidArgumentException('Scheme and host are required');
        }

        $insecureConnectionAllowed = false;
        switch ($components['scheme']) {
            case 'smtp+tls':
                $connection = new TlsConnection(
                    $components['host'],
                    $components['port'] ?? 465,
                    new SecureConnectionOptions()
                );
                break;
            case 'smtp+ssl':
                $connection = new SslConnection(
                    $components['host'],
                    $components['port'] ?? 465,
                    new SecureConnectionOptions()
                );
                break;
            case 'smtp+plain':
                $insecureConnectionAllowed = true;
                $connection = new PlainTcpConnection(
                    $components['host'],
                    $components['port'] ?? 25
                );
                break;
            case 'smtp':
                $connection = new PlainTcpConnection(
                    $components['host'],
                    $components['port'] ?? 587
                );
                break;
            default:
                throw new \InvalidArgumentException(
                    'Use smtp:// smtp+tls:// smtp+ssl:// smtp+plain://'
                );
        }

        $factory = new self($connection);
        $factory->insecureConnectionAllowed = $insecureConnectionAllowed;

        if (isset($components['user']) && isset($components['pass'])) {
            $factory->authMethod = Client::AUTH_AUTO;
            $factory->username = urldecode($components['user']);
            $factory->password = urldecode($components['pass']);
        }

        if (isset($components['query'])) {
            parse_str($components['query'], $query);

            if (isset($query['ehlo'])) {
                $factory->ehlo = $query['ehlo'];
            }

            if (isset($query['timeout'])) {
                $factory->timeout = (float)$query['timeout'];
            }

            if (isset($query['reconnectAfter'])) {
                $factory->reconnectAfter = $query['reconnectAfter'];
            }

            if (isset($query['crypto'])) {
                // @codeCoverageIgnoreStart
                switch ($query['crypto']) {
                    case 'best':
                        $factory->crypto = CryptoConstant::TYPE_BEST_PRACTISE;
                        break;
                    case 'secure':
                        $factory->crypto = CryptoConstant::TYPE_SECURE;
                        break;
                    case 'none':
                        $factory->crypto = CryptoConstant::TYPE_NONE;
                        break;
                    default:
                        $factory->crypto = (int)$query['crypto'];
                        break;
                }
                // @codeCoverageIgnoreEnd
            }
        }

        return $factory;
    }
}
