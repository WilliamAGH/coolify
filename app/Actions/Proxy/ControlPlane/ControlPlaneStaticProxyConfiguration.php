<?php

namespace App\Actions\Proxy\ControlPlane;

final readonly class ControlPlaneStaticProxyConfiguration
{
    public string $predecessorProxySha256;

    public string $replacementProxySha256;

    public string $sourceOverrideSha256;

    public function __construct(
        public string $predecessorProxyYaml,
        public string $replacementProxyYaml,
        public string $sourceOverrideYaml,
        public int $appPort,
        public ControlPlaneProxyExposure $exposure,
    ) {
        $this->predecessorProxySha256 = hash('sha256', $predecessorProxyYaml);
        $this->replacementProxySha256 = hash('sha256', $replacementProxyYaml);
        $this->sourceOverrideSha256 = hash('sha256', $sourceOverrideYaml);
    }
}
