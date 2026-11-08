<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Format;

use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectValueInterface;

/** @internal */
final readonly class CallToValue implements ObjectValueInterface
{
	public function __construct(
		private int $format,
		private string $urlTemplate,
		private string $clickHandler,
		private bool $normalizeNumber,
	)
	{
	}

	public function getFormat(): int
	{
		return $this->format;
	}

	public function getUrlTemplate(): string
	{
		return $this->urlTemplate;
	}

	public function getClickHandler(): string
	{
		return $this->clickHandler;
	}

	public function shouldNormalizeNumber(): bool
	{
		return $this->normalizeNumber;
	}

	public function toArray(): array
	{
		return [
			'format' => $this->format,
			'urlTemplate' => $this->urlTemplate,
			'clickHandler' => $this->clickHandler,
			'normalizeNumber' => $this->normalizeNumber,
		];
	}
}
