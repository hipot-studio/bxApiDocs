<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Connection;

use Bitrix\Main\Web\Uri;

final class PlatformSource
{
	public function __construct(
		public readonly ?string $namespace,
		public readonly ?string $server,
		#[\SensitiveParameter] private readonly string $webhookSecret,
		public readonly bool $departing = false,
		public readonly ?string $policyBindingId = null,
		public readonly int $receiveMin = 1,
		public readonly int $until = 0,
	)
	{
	}

	public static function current(?string $server, #[\SensitiveParameter] string $webhookSecret): self
	{
		return new self(PlatformNamespace::compute($server ?? '', $webhookSecret), $server, $webhookSecret);
	}

	public function webhookSecret(): string
	{
		return $this->webhookSecret;
	}

	public function storedNamespace(): string
	{
		return $this->namespace ?? '';
	}

	public function logKind(): string
	{
		return $this->departing ? 'departing' : 'current';
	}

	public function allowsFileUrl(string $url): bool
	{
		$uri = new Uri($url);
		if (!in_array($uri->getScheme(), ['http', 'https'], true) || $this->server === null || $this->server === '')
		{
			return false;
		}
		$expected = new Uri($this->server);

		return $expected->getHost() !== ''
			&& mb_strtolower($uri->getHost()) === mb_strtolower($expected->getHost())
			&& $uri->getPort() === $expected->getPort();
	}

	/** @param list<PlatformSource> $candidates */
	public static function select(array $candidates, string $event, array $payload): ?self
	{
		if ($candidates === [])
		{
			return null;
		}
		$url = $payload['file_url'] ?? null;
		if ($event === 'document.saved' && is_string($url))
		{
			foreach ($candidates as $candidate)
			{
				if ($candidate->allowsFileUrl($url))
				{
					return $candidate;
				}
			}
		}

		return $candidates[0];
	}

	public function __debugInfo(): array
	{
		return [
			'namespace' => $this->namespace,
			'server' => $this->server,
			'departing' => $this->departing,
			'policyBindingId' => $this->policyBindingId,
			'receiveMin' => $this->receiveMin,
			'until' => $this->until,
		];
	}
}
