<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\ReadRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Provider\Params\GridParams;

final class RegistryProvider
{
	public function getList(int $userId, GridParams $params): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getFacts($userId, $params);
	}

	public function getById(int $userId, int $factId): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getFact($userId, $factId);
	}

	public function getPending(int $userId, ?int $cursor = null, int $limit = 50): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getPending($userId, $cursor, $limit);
	}
}
