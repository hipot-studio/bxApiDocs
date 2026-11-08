<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\LegalRegistry;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Error;

final class FeatureGate
{
	public static function requireEnabled(): RegistryResult
	{
		try
		{
			if (class_exists(LegalRegistry::class) && Feature::enabled(LegalRegistry::class))
			{
				return new RegistryResult();
			}
		}
		catch (\Throwable)
		{
			// An unavailable flag store must not expose registry data or infrastructure errors.
		}

		return (new RegistryResult())->addError(new Error('Registry policy is unavailable.', 'POLICY_UNAVAILABLE'));
	}
}
