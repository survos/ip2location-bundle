<?php

declare(strict_types=1);

namespace Survos\Ip2LocationBundle\Service;

use IP2LocationIO\DomainWhois;
use IP2LocationIO\IPGeolocation;
use IP2LocationIO\Configuration;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;

class Ip2LocationService
{
    public function __construct(
        private ?string $apiKey=null,
        private ?string $localhostIp=null,
        private ?CacheInterface $cache=null
    )
    {
    }

    public function domainWhoIs(string $domain)
    {
        return $this->cache
            ? $this->cache->get($domain, fn(CacheItem $item) => $this->lookupDomain($domain))
            : $this->lookupDomain($domain);
    }

    public function getIPGeolocation(string $ip)
    {
        if ($ip === '127.0.0.1') {
            $ip = $this->localhostIp;
        }
        return $this->cache
            ? $this->cache->get($ip, fn(CacheItem $item) => $this->lookupIpLocation($ip))
            : $this->lookupIpLocation($ip);
    }

    private function lookupIpLocation(string $ip)
    {
        $config = new Configuration($this->apiKey);
        return  (new IPGeolocation($config))->lookup($ip);
    }

    private function lookupDomain(string $domain)
    {
        $config = new Configuration($this->apiKey);
        return  (new DomainWhois($config))->lookup($domain);
    }

}
