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
		private ?string $disabledReason,
		private ?string $restrictionSliderCode,
	)
	{
	}

	public static function unavailable(string $disabledReason, ?string $restrictionSliderCode = null): self
	{
		if (trim($disabledReason) === '')
		{
			throw new InvalidArgumentException('Navigation disabled reason must not be empty.');
		}

		if ($restrictionSliderCode !== null && trim($restrictionSliderCode) === '')
		{
			throw new InvalidArgumentException('Navigation restriction slider code must not be empty.');
		}

		return new self(false, null, $disabledReason, $restrictionSliderCode);
	}

	public static function available(string $url): self
	{
		if (trim($url) === '')
		{
			throw new InvalidArgumentException('Navigation URL must not be empty.');
		}

		return new self(true, $url, null, null);
	}

	/**
	 * @return array{available: false, disabledReason: string, restrictionSliderCode: ?string}
	 *  |array{available: true, url: string}
	 */
	public function toArray(): array
	{
		return $this->available
			? ['available' => true, 'url' => $this->url]
			: [
				'available' => false,
				'disabledReason' => $this->disabledReason,
				'restrictionSliderCode' => $this->restrictionSliderCode,
			];
	}
}
