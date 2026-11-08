<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class PolicyGate
{
	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		if ($locator->has(self::class))
		{
			$service = $locator->get(self::class);
			if ($service instanceof self)
			{
				return $service;
			}
		}

		return new self();
	}

	public function canCollectEvidence(?array $metadata = null): bool
	{
		return false;
	}

	public function canPurge(): bool
	{
		return false;
	}

	public function requireCollection(): RegistryResult
	{
		return $this->canCollectEvidence() ? new RegistryResult() : self::unavailable();
	}

	public function requirePurge(): RegistryResult
	{
		return $this->canPurge() ? new RegistryResult() : self::unavailable();
	}

	private static function unavailable(): RegistryResult
	{
		return (new RegistryResult())->addError(new Error('Registry policy is unavailable.', 'POLICY_UNAVAILABLE'));
	}
}
