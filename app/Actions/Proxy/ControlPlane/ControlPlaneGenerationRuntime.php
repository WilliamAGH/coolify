<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use JsonException;

final readonly class ControlPlaneGenerationRuntime
{
    private const SERIALIZED_KEYS = [
        'predecessor',
        'successor',
        'writer',
    ];

    private const RUNTIME_MEMBER_KEYS = [
        'container_id',
        'image_id',
    ];

    private const WRITER_KEYS = [
        'name',
        'container_id',
    ];

    /**
     * @param  array<string, array{container_id: string, image_id: string}>  $predecessorRuntime
     * @param  array<string, array{container_id: string, image_id: string}>  $successorRuntime
     */
    public function __construct(
        public array $predecessorRuntime,
        public array $successorRuntime,
        public string $writerContainerName,
        public string $writerContainerId,
    ) {
        self::assertRuntime($predecessorRuntime, 'predecessor');
        self::assertRuntime($successorRuntime, 'successor');

        self::assertRoutedDnsName($writerContainerName, 'writer container name');
        self::assertDockerId($writerContainerId, 'writer container ID');

        if (! array_key_exists($writerContainerName, $successorRuntime)) {
            throw new InvalidArgumentException('The control-plane writer container name must identify a successor runtime member.');
        }
        if (! hash_equals($successorRuntime[$writerContainerName]['container_id'], $writerContainerId)) {
            throw new InvalidArgumentException('The control-plane writer container ID must match its successor runtime member.');
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $value = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The control-plane generation runtime JSON is malformed.', previous: $exception);
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The control-plane generation runtime JSON has an unexpected shape.');
        }

        $runtime = self::fromArray($value);
        if (! hash_equals($runtime->toJson(), $json)) {
            throw new InvalidArgumentException('The control-plane generation runtime JSON is not canonical.');
        }

        return $runtime;
    }

    /**
     * @param  array{
     *     predecessor: array<string, array{container_id: string, image_id: string}>,
     *     successor: array<string, array{container_id: string, image_id: string}>,
     *     writer: array{name: string, container_id: string}
     * }  $runtime
     */
    public static function fromArray(array $runtime): self
    {
        self::assertExactKeys($runtime, self::SERIALIZED_KEYS, 'runtime');

        $predecessorRuntime = self::decodeRuntime($runtime['predecessor'], 'predecessor');
        $successorRuntime = self::decodeRuntime($runtime['successor'], 'successor');
        $writer = self::decodeWriter($runtime['writer']);

        $instance = new self(
            predecessorRuntime: $predecessorRuntime,
            successorRuntime: $successorRuntime,
            writerContainerName: $writer['name'],
            writerContainerId: $writer['container_id'],
        );

        if ($runtime !== $instance->toArray()) {
            throw new InvalidArgumentException('The control-plane generation runtime array is not canonical.');
        }

        return $instance;
    }

    public function predecessorDockerIdFor(string $routedDnsName): string
    {
        return $this->dockerIdFor($this->predecessorRuntime, $routedDnsName, 'predecessor');
    }

    public function successorDockerIdFor(string $routedDnsName): string
    {
        return $this->dockerIdFor($this->successorRuntime, $routedDnsName, 'successor');
    }

    /** @return array{name: string, container_id: string, image_id: string} */
    public function writerIdentity(): array
    {
        return [
            'name' => $this->writerContainerName,
            'container_id' => $this->writerContainerId,
            'image_id' => $this->successorRuntime[$this->writerContainerName]['image_id'],
        ];
    }

    /**
     * @return array{
     *     predecessor: array<string, array{container_id: string, image_id: string}>,
     *     successor: array<string, array{container_id: string, image_id: string}>,
     *     writer: array{name: string, container_id: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'predecessor' => $this->predecessorRuntime,
            'successor' => $this->successorRuntime,
            'writer' => [
                'name' => $this->writerContainerName,
                'container_id' => $this->writerContainerId,
            ],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, array{container_id: string, image_id: string}>  $runtime
     */
    private function dockerIdFor(array $runtime, string $routedDnsName, string $generation): string
    {
        self::assertRoutedDnsName($routedDnsName, 'routed DNS name');

        if (! array_key_exists($routedDnsName, $runtime)) {
            throw new InvalidArgumentException("The control-plane {$generation} runtime has no member for the routed DNS name.");
        }

        return $runtime[$routedDnsName]['container_id'];
    }

    /** @return array<string, array{container_id: string, image_id: string}> */
    private static function decodeRuntime(mixed $value, string $generation): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("The control-plane {$generation} runtime is invalid.");
        }

        self::assertRuntime($value, $generation);

        return $value;
    }

    /** @return array{name: string, container_id: string} */
    private static function decodeWriter(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane writer identity is invalid.');
        }
        self::assertExactKeys($value, self::WRITER_KEYS, 'writer identity');

        if (! is_string($value['name']) || ! is_string($value['container_id'])) {
            throw new InvalidArgumentException('The control-plane writer identity fields must be strings.');
        }

        return [
            'name' => $value['name'],
            'container_id' => $value['container_id'],
        ];
    }

    /**
     * @param  array<string, array{container_id: string, image_id: string}>  $runtime
     */
    private static function assertRuntime(array $runtime, string $generation): void
    {
        if ($runtime === [] || array_is_list($runtime)) {
            throw new InvalidArgumentException("The control-plane {$generation} runtime must be a non-empty member map.");
        }

        $memberNames = array_keys($runtime);
        foreach ($memberNames as $memberName) {
            if (! is_string($memberName)) {
                throw new InvalidArgumentException("The control-plane {$generation} runtime member names must be strings.");
            }
            self::assertRoutedDnsName($memberName, "{$generation} runtime member name");
        }

        $sortedMemberNames = $memberNames;
        sort($sortedMemberNames, SORT_STRING);
        if ($memberNames !== $sortedMemberNames
            || count(array_unique($memberNames, SORT_STRING)) !== count($memberNames)) {
            throw new InvalidArgumentException("The control-plane {$generation} runtime member names must be sorted and unique.");
        }

        foreach ($runtime as $member) {
            if (! is_array($member)) {
                throw new InvalidArgumentException("The control-plane {$generation} runtime member is invalid.");
            }
            self::assertExactKeys($member, self::RUNTIME_MEMBER_KEYS, "{$generation} runtime member");

            if (! is_string($member['container_id']) || ! is_string($member['image_id'])) {
                throw new InvalidArgumentException("The control-plane {$generation} runtime member identifiers must be strings.");
            }

            self::assertDockerId($member['container_id'], "{$generation} runtime Docker ID");
            self::assertImageId($member['image_id'], "{$generation} runtime image ID");
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function assertExactKeys(array $value, array $expected, string $label): void
    {
        if (array_is_list($value) || array_keys($value) !== $expected) {
            throw new InvalidArgumentException("The control-plane {$label} has an unexpected shape.");
        }
    }

    private static function assertRoutedDnsName(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$label} must be a Docker-safe routed DNS name.");
        }
    }

    private static function assertDockerId(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$label} must be a full Docker ID.");
        }
    }

    private static function assertImageId(string $value, string $label): void
    {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$label} must be a full sha256 image ID.");
        }
    }
}
