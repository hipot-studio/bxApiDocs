<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Throwable;

final class VibePlusCatalogRedirect
{
	public function __construct(
		private readonly ?VibePlusPolicy $policy = null,
		private readonly ?VibePlusUpsellProvider $upsellProvider = null,
	)
	{}

	public function getPromoterCodeForUser(int $userId): ?string
	{
		try
		{
			if ($userId <= 0 || ($this->policy ?? new VibePlusPolicy())->getAvailability() !== false)
			{
				return null;
			}

			$promoterCode = ($this->upsellProvider ?? new VibePlusUpsellProvider())
				->getForUser($userId)
				?->getPromoterCode()
			;

			return is_string($promoterCode) && $promoterCode !== '' ? $promoterCode : null;
		}
		catch (Throwable)
		{
			return null;
		}
	}
}
