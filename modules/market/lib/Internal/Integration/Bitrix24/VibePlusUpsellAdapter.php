<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Public\Service\VibePlus\RuntimeStateProvider;
use Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider;
use Bitrix\Bitrix24\Public\ValueObject\VibePlusCta;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Throwable;

final readonly class VibePlusUpsellAdapter
{
	public function __construct(
		private ?object $upsellProvider = null,
		private ?object $runtimeStateProvider = null,
	)
	{}

	public function getUpsellAction(): ?array
	{
		try
		{
			$provider = $this->resolveProvider(
				UpsellProjectionProvider::class,
				['getBuyProjection'],
				$this->upsellProvider,
			);
			if ($provider === null)
			{
				return null;
			}

			$projection = $provider->getBuyProjection();
			if (
				!is_object($projection)
				|| !method_exists($projection, 'getCta')
				|| !method_exists($projection, 'getCtaTarget')
			)
			{
				return null;
			}

			$cta = $projection->getCta();
			$target = $projection->getCtaTarget();
			if ($cta !== 'buy' || !$this->isCtaTargetValid($target))
			{
				return null;
			}

			return [
				'type' => $cta,
				'target' => $target,
			];
		}
		catch (Throwable)
		{
			return null;
		}
	}

	public function getTransitionState(): array
	{
		try
		{
			$provider = $this->resolveProvider(
				RuntimeStateProvider::class,
				['isTransitionPeriodActive', 'getTransitionPeriodEndAt'],
				$this->runtimeStateProvider,
			);
			if ($provider === null)
			{
				return self::inactiveTransitionState();
			}

			$active = $provider->isTransitionPeriodActive();
			$endsAt = $provider->getTransitionPeriodEndAt();
			if (
				!is_bool($active)
				|| ($endsAt !== null && (!is_int($endsAt) || $endsAt <= 0))
			)
			{
				return self::inactiveTransitionState();
			}

			return [
				'active' => $active,
				'endsAt' => $active ? $endsAt : null,
			];
		}
		catch (Throwable)
		{
			return self::inactiveTransitionState();
		}
	}

	private function resolveProvider(string $providerClass, array $requiredMethods, ?object $provider): ?object
	{
		if ($provider === null)
		{
			if (!Loader::includeModule('bitrix24') || !class_exists($providerClass))
			{
				return null;
			}

			$serviceLocator = ServiceLocator::getInstance();
			if (!$serviceLocator->has($providerClass))
			{
				return null;
			}

			$provider = $serviceLocator->get($providerClass);
		}

		if (!is_object($provider))
		{
			return null;
		}

		foreach ($requiredMethods as $method)
		{
			if (!method_exists($provider, $method))
			{
				return null;
			}
		}

		return $provider;
	}

	private function isCtaTargetValid(mixed $target): bool
	{
		if (
			!is_string($target)
			|| !Loader::includeModule('bitrix24')
			|| !class_exists(VibePlusCta::class)
			|| !method_exists(VibePlusCta::class, 'isTargetValid')
		)
		{
			return false;
		}

		return VibePlusCta::isTargetValid($target);
	}

	private static function inactiveTransitionState(): array
	{
		return [
			'active' => false,
			'endsAt' => null,
		];
	}
}
