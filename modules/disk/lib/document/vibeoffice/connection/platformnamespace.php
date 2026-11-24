<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Connection;

use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Uri;

final class PlatformNamespace
{
	public const INITIAL_OPTION = 'disk_vibeoffice_initial_namespace';

	public function __construct(private readonly Configuration $configuration)
	{
	}

	public static function origin(string $server): ?string
	{
		$uri = new Uri($server);
		$scheme = $uri->getScheme();
		if (!in_array($scheme, ['http', 'https'], true) || $uri->getHost() === '')
		{
			return null;
		}
		$port = $uri->getPort();

		return $scheme . '://' . $uri->getHost()
			. ($port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port);
	}

	public static function compute(string $server, #[\SensitiveParameter] string $webhookSecret): ?string
	{
		$origin = self::origin($server);
		if ($origin === null || $webhookSecret === '')
		{
			return null;
		}

		return hash('sha256', $origin . "\0" . hash_hmac('sha256', 'disk-vibeoffice-namespace-v1', $webhookSecret));
	}

	public function current(): ?string
	{
		return self::compute($this->configuration->getServer() ?? '', $this->configuration->getWebhookSecret() ?? '');
	}

	public function initial(): ?string
	{
		return $this->recordedInitial() ?? $this->current();
	}

	public function deliveryValues(?string $namespace): array
	{
		if ($namespace === null)
		{
			return [''];
		}

		return $namespace === $this->initial() ? [$namespace, ''] : [$namespace];
	}

	public function documentValues(): ?array
	{
		$current = $this->current();

		return $current === null ? null : $this->deliveryValues($current);
	}

	public function isCurrent(string $stored): bool
	{
		$values = $this->documentValues();

		return $values === null || in_array($stored, $values, true);
	}

	public function hasSwitched(): bool
	{
		$current = $this->current();

		return $current !== null && $current !== $this->initial();
	}

	public function initialToRecord(): ?string
	{
		return $this->recordedInitial() === null ? $this->current() : null;
	}

	private function recordedInitial(): ?string
	{
		$value = Option::getRealValue('disk', self::INITIAL_OPTION, '');

		return is_string($value) && preg_match('/^[a-f0-9]{64}$/Di', $value) === 1 ? $value : null;
	}
}
