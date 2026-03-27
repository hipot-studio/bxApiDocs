<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\Integrator;

use Bitrix\Bitrix24\Public\Command\Partner\GetInfoCommand;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Entity\User\PartnerInfo;
use Bitrix\Main\Loader;

class PartnerInfoProvider
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('bitrix24');
	}

	public function getByUser(User $user): ?PartnerInfo
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		$command = new GetInfoCommand($user->getEmail());
		$result = $command->run();

		if (!$result->isSuccess())
		{
			return null;
		}

		$partnerData = $result->getData();

		if (isset($partnerData['partnerName'], $partnerData['partnerId']))
		{
			return new PartnerInfo(
				userId: $user->getId(),
				integratorId: (int)$partnerData['partnerId'],
				integratorName: $partnerData['partnerName'],
			);
		}

		return null;
	}
}
