<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Service;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Security\ParameterSigner;
use Bitrix\Disk\ZipNginx;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;

class DiskArchiveLinkService
{
	private const DOWNLOAD_ACTION = 'downloadArchiveByEntity';

	public function getByTaskId(int $taskId): ?string
	{
		return $this->get(
			entityId: $taskId,
			entityType: UserField::TASK,
			fieldName: UserField::TASK_ATTACHMENTS,
		);
	}

	public function getByTemplateId(int $templateId): ?string
	{
		return $this->get(
			entityId: $templateId,
			entityType: UserField::TEMPLATE,
			fieldName: UserField::TASK_ATTACHMENTS,
		);
	}

	public function getByResultId(int $resultId): ?string
	{
		return $this->get(
			entityId: $resultId,
			entityType: UserField::RESULT,
			fieldName: UserField::TASK_RESULT,
		);
	}

	/**
	 * Returns a map of resultId => archive link (or null) for multiple results at once.
	 * Performs the disk-module and ZipNginx checks only once.
	 *
	 * @param int[] $resultIds
	 * @return array<int, string|null>
	 */
	public function getByResultIds(array $resultIds): array
	{
		if (empty($resultIds))
		{
			return [];
		}

		if (!Loader::includeModule('disk'))
		{
			return array_fill_keys($resultIds, null);
		}

		if (!ZipNginx\Configuration::isEnabled())
		{
			return array_fill_keys($resultIds, null);
		}

		$urlManager = Driver::getInstance()->getUrlManager();

		$map = [];
		foreach ($resultIds as $resultId)
		{
			$map[$resultId] = $urlManager::getUrlUfController(self::DOWNLOAD_ACTION, [
				'entityId' => $resultId,
				'entity' => UserField::RESULT,
				'fieldName' => UserField::TASK_RESULT,
				'signature' => ParameterSigner::getEntityArchiveSignature(
					entity: UserField::RESULT,
					entityId: $resultId,
					fieldName: UserField::TASK_RESULT,
				),
			]);
		}

		return $map;
	}

	protected function get(int $entityId, string $entityType, string $fieldName): ?string
	{
		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		if (!ZipNginx\Configuration::isEnabled())
		{
			return null;
		}

		$urlManager = Driver::getInstance()->getUrlManager();

		return $urlManager::getUrlUfController(self::DOWNLOAD_ACTION, [
			'entityId' => $entityId,
			'entity' => $entityType,
			'fieldName' => $fieldName,
			'signature' => ParameterSigner::getEntityArchiveSignature(
				entity: $entityType,
				entityId: $entityId,
				fieldName: $fieldName,
			),
		]);
	}
}
