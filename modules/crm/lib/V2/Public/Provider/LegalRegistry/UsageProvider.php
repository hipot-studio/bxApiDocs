<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\ReadRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;

final class UsageProvider
{
	public function getByDocument(int $userId, int $documentId, ?int $cursor = null, int $limit = 50): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getUsage($userId, $documentId, $cursor, $limit);
	}
}
