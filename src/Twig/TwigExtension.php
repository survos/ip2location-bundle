<?php

namespace Survos\Ip2LocationBundle\Twig;

use Survos\Ip2LocationBundle\Service\Ip2LocationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function __construct(private Ip2LocationService $ip2LocationService)
    {
    }

    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, add ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('domainWhoIs', fn(string $domain) => $this->ip2LocationService->domainWhoIs($domain)),
            new TwigFunction('ipGeolocation', fn(string $ip) => $this->ip2LocationService->getIPGeolocation($ip)),
            new TwigFunction('isLocalhost', fn(string $ip) => $ip === '127.0.0.1'),
        ];
    }
}
