<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use InvalidArgumentException;

/** @internal */
final readonly class NavigationTarget
{
	private function __construct(
		private bool $available,
		private ?string $url,
	)
	{
	}

	public static function unavailable(): self
	{
		return new self(false, null);
	}

	public static function available(string $url): self
	{
		if (trim($url) === '')
		{
			throw new InvalidArgumentException('Navigation URL must not be empty.');
		}

		return new self(true, $url);
	}

	/** @return array{available: false}|array{available: true, url: string} */
	public function toArray(): array
	{
		return $this->available
			? ['available' => true, 'url' => $this->url]
			: ['available' => false];
	}
}
