<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\ReadRepository;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormBindingsService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Error;

final class UsageProvider
{
	/** Returns the complete current editor projection, including order and notice. */
	public function getBindingsByForm(int $userId, int $formId): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }
		try
		{
			return (new RegistryResult())->setBindings(FormBindingsService::getInstance()->getByForm($userId, $formId));
		}
		catch (\UnexpectedValueException)
		{
			return (new RegistryResult())->addError(new Error('', 'NOT_FOUND_OR_DENIED'));
		}
		catch (\Throwable)
		{
			return (new RegistryResult())->addError(new Error('', 'STORAGE_ERROR'));
		}
	}

	/** Returns current document bindings for an existing CRM form. */
	public function getByForm(int $userId, int $formId): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getUsageByForm($userId, $formId);
	}

	/** Returns distinct existing CRM form counts across every version and historical interval. */
	public function getCountByDocuments(int $userId, array $documentIds): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getFormCounts($userId, $documentIds);
	}

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
