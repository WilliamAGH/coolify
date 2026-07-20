<?php

it('does not warn when an optional domain list is absent', function (?string $domains): void {
    expect(sslipDomainWarning($domains))->toBeFalse();
})->with([
    'null' => null,
    'empty' => '',
    'whitespace' => '   ',
]);

it('warns only for HTTPS sslip domains', function (string $domains, bool $expected): void {
    expect(sslipDomainWarning($domains))->toBe($expected);
})->with([
    'HTTPS sslip' => ['https://app.127.0.0.1.sslip.io', true],
    'mixed list containing HTTPS sslip' => ['https://example.com,http://app.127.0.0.1.sslip.io,https://admin.127.0.0.1.sslip.io', true],
    'HTTP sslip' => ['http://app.127.0.0.1.sslip.io', false],
    'ordinary HTTPS domain' => ['https://example.com', false],
]);
