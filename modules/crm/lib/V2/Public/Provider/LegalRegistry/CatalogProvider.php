<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\LegalRegistry;

use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\ReadRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogChoice;
use Bitrix\Main\Error;
use Bitrix\Main\Application;
use Bitrix\Main\Provider\Params\GridParams;

final class CatalogProvider
{
	public function getSchemaAccess(int $userId): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }
		try
		{
			return $userId > 0 && RegistryAccess::forUser($userId)->canReadForm()
				? new RegistryResult() : self::error('NOT_FOUND_OR_DENIED');
		}
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
	}

	public function getCard(int $userId, int $documentId, ?int $versionId = null): RegistryResult
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$result = $this->getById($userId, $documentId);
			if (!$result->isSuccess()) { return $result; }
			$document = $result->getDocument();
			if ($document === null) { return self::error('STORAGE_ERROR'); }
			$version = null;
			if ($versionId !== null)
			{
				foreach ($document->versions as $candidate)
				{
					if ($candidate->id === $versionId) { $version = $candidate; break; }
				}
				if ($version === null) { return self::error('NOT_FOUND_OR_DENIED'); }
			}
			elseif ($document->versions !== [])
			{
				$version = $document->versions[array_key_last($document->versions)];
			}

			return $result->setVersions($version === null ? [] : [$version])
				->setCardRevision($document->cardRevision($version));
		}
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
		finally { $pool->useMasterOnly(false); }
	}

	/** Checks a new choice; an existing archived binding is read through UsageProvider. */
	public function verifySelectableVersion(int $userId, int $documentId, int $versionId): RegistryResult
	{
		$result = $this->getCard($userId, $documentId, $versionId);
		if (!$result->isSuccess()) { return $result; }
		$document = $result->getDocument();
		$version = $result->getVersions()[0] ?? null;
		if ($document === null || $version === null || !(new CatalogChoice(
			$document->id, $version->id, $version->number, $document->name, $version->state,
			$document->archived, $version->validFrom, $version->validTo,
		))->eligible)
		{
			return self::error('INVALID_STATE');
		}

		return $result;
	}

	/**
	 * Returns one bounded page of exact version metadata for a readable existing form.
	 *
	 * @param int $userId
	 * @param int $formId
	 * @param ?int $cursor Last version ID from the preceding page.
	 * @param int $limit Maximum number of version choices, from 1 to 100.
	 * @param string $query Document name fragment or exact document ID.
	 * @return RegistryResult
	 */
	public function getChoicesByForm(
		int $userId,
		int $formId,
		?int $cursor = null,
		int $limit = 50,
		string $query = '',
	): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return (new ReadRepository())->getVersionChoices($userId, $formId, $cursor, $limit, $query);
		}
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
		finally { $pool->useMasterOnly(false); }
	}
	public function getById(int $userId, int $documentId): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getDocument($userId, $documentId);
	}

	/** Searches documents by ID, name fragment, responsible user and tag. */
	public function getList(int $userId, GridParams $params, string $query = '', bool $includeTotalCount = true)
		: RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new ReadRepository())->getDocuments($userId, $params, $query, $includeTotalCount);
	}

	/** Returns only the document and version fields displayed by the catalog grid. */
	public function getListItems(int $userId, GridParams $params, string $query = ''): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }

		return (new ReadRepository())->getDocuments($userId, $params, $query, false, true);
	}

	private static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new Error('', $code));
	}
}
