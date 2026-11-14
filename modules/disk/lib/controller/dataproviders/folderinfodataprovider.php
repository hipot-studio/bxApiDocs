<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\DataProviders;

use Bitrix\Disk;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class FolderInfoDataProvider
{
	public function getInfo(
		Disk\Folder $folder,
		CurrentUser $currentUser,
		bool $shouldCheckUnifiedLink,
	): Result
	{
		$result = new Result();
		$parameters = [
			'filter' => [
				'=PARENT_ID' => $folder->getRealObjectId(),
				'=DELETED_TYPE' => Disk\Internals\ObjectTable::DELETED_TYPE_NONE,
			],
			'select' => [
				'DIRECT_FILES_COUNT',
				'DIRECT_FOLDERS_COUNT',
				'DIRECT_FILES_SIZE',
			],
			'runtime' => [
				new ExpressionField(
					'DIRECT_FILES_COUNT',
					'COALESCE(SUM(CASE WHEN %s = ' . Disk\Internals\ObjectTable::TYPE_FILE . ' THEN 1 ELSE 0 END), 0)',
					'TYPE',
				),
				new ExpressionField(
					'DIRECT_FOLDERS_COUNT',
					'COALESCE(SUM(CASE WHEN %s = ' . Disk\Internals\ObjectTable::TYPE_FOLDER . ' THEN 1 ELSE 0 END), 0)',
					'TYPE',
				),
				new ExpressionField(
					'DIRECT_FILES_SIZE',
					'COALESCE(SUM(CASE WHEN %s = ' . Disk\Internals\ObjectTable::TYPE_FILE . ' THEN %s ELSE 0 END), 0)',
					['TYPE', 'SIZE'],
				),
			],
		];

		if (!$shouldCheckUnifiedLink)
		{
			$securityContext = $this->getSecurityContext($folder, $currentUser);
			if (!$securityContext)
			{
				return $result->addError(new Error('Could not find storage for folder.'));
			}

			$parameters = Disk\Driver::getInstance()->getRightsManager()->addRightsCheck(
				securityContext: $securityContext,
				parameters: $parameters,
				specificColumns: ['ID', 'CREATED_BY'],
			);
		}

		$row = $this->fetchAggregate($parameters);
		$result->setData([
			'directFilesCount' => (int)($row['DIRECT_FILES_COUNT'] ?? 0),
			'directFoldersCount' => (int)($row['DIRECT_FOLDERS_COUNT'] ?? 0),
			'directFilesSize' => (int)($row['DIRECT_FILES_SIZE'] ?? 0),
		]);

		return $result;
	}

	protected function getSecurityContext(Disk\Folder $folder, CurrentUser $currentUser): ?SecurityContext
	{
		return $folder->getStorage()?->getSecurityContext($currentUser->getId(), false);
	}

	protected function fetchAggregate(array $parameters): array|false
	{
		return Disk\Internals\ObjectTable::getList($parameters)->fetch();
	}
}
