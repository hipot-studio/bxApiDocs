<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider;
use Bitrix\Bitrix24\Public\ValueObject\VibePlusUpsellProjection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Throwable;

final class UpsellHelperCodeProvider
{
	public function __construct(private readonly ?object $provider = null)
	{}

	public function getHelperCode(string $fallback): string
	{
		try
		{
			$projection = $this->resolveProvider()?->getProjection();
		}
		catch (Throwable)
		{
			return $fallback;
		}

		return $projection instanceof VibePlusUpsellProjection
			? $projection->getPromoterCode()
			: $fallback;
	}

	private function resolveProvider(): ?object
	{
		if ($this->provider !== null)
		{
			return $this->provider;
		}

		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$serviceLocator = ServiceLocator::getInstance();

		return $serviceLocator->has(UpsellProjectionProvider::class)
			? $serviceLocator->get(UpsellProjectionProvider::class)
			: null;
	}
}
