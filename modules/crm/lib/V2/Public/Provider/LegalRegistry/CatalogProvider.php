<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\ReadRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Provider\Params\GridParams;

final class CatalogProvider
{
	public function getById(int $userId, int $documentId): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getDocument($userId, $documentId);
	}

	public function getList(int $userId, GridParams $params): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getDocuments($userId, $params);
	}
}
