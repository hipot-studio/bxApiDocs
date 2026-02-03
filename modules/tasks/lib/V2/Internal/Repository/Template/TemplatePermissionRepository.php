<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntity;
use Bitrix\Tasks\V2\Internal\Entity\Template\PermissionCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\AccessEntityTypeMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\TemplatePermissionMapper;

class TemplatePermissionRepository implements TemplatePermissionRepositoryInterface
{
	public function __construct(
		private readonly AccessEntityRepositoryInterface $accessEntityRepository,
		private readonly TemplatePermissionMapper $templatePermissionMapper,
		private readonly AccessEntityTypeMapper $accessEntityTypeMapper,
	)
	{

	}

	public function getPermissions(int $templateId): PermissionCollection
	{
		$rows = TasksTemplatePermissionTable::query()
			->setSelect(['*'])
			->where('TEMPLATE_ID', $templateId)
			->exec()
			->fetchAll()
		;

		$accessCodes = array_column($rows, 'ACCESS_CODE');

		$accessEntities = $this->accessEntityRepository->getByAccessCodes($accessCodes);

		foreach ($rows as &$row)
		{
			$accessCode = new AccessCode((string)$row['ACCESS_CODE']);

			$row['ACCESS_ENTITY'] = $accessEntities->find(
				fn (AccessEntity $accessEntity): bool
					=> $accessEntity->id === $accessCode->getEntityId()
						&& $accessEntity->type === $this->accessEntityTypeMapper->mapToEnum($accessCode->getEntityType())
			);

			$row['ACCESS_ENTITY'] ??= new AccessEntity(
				id: $accessCode->getEntityId(),
				type: $this->accessEntityTypeMapper->mapToEnum($accessCode->getEntityType()),
			);
		}

		return $this->templatePermissionMapper->mapToCollection($rows);
	}
}
