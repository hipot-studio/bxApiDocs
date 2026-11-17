<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Registry;

use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;
use Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;
use Bitrix\Tasks\V2\Internal\DI\Container;

class TemplateAccessCacheLoader
{
	private const USER_DEPARTMENT_PRELOAD_CHUNK_SIZE = 300;

	/**
	 * Only successfully preloaded model instances are retained; invalidated models have a new identity and reload.
	 *
	 * @var array<int, array<int, TemplateModel>>
	 */
	private array $preloadedModelsByUserId = [];

	public function preload(array $templateIds): void
	{
		$templateIds = $this->normalizeIds($templateIds);
		if ($templateIds === [])
		{
			return;
		}

		TemplateRegistry::getInstance()->load($templateIds);
	}

	public function preloadForAccess(int $userId, array $templateIds): void
	{
		$templateIds = $this->normalizeIds($templateIds);
		if ($templateIds === [])
		{
			return;
		}

		$this->preload($templateIds);

		$modelsByTemplateId = [];
		foreach ($templateIds as $templateId)
		{
			$model = TemplateModel::createFromId($templateId);
			if (($this->preloadedModelsByUserId[$userId][$templateId] ?? null) === $model)
			{
				continue;
			}

			unset($this->preloadedModelsByUserId[$userId][$templateId]);
			$modelsByTemplateId[$templateId] = $model;
		}

		if ($modelsByTemplateId === [])
		{
			return;
		}

		$idsToPreload = array_keys($modelsByTemplateId);

		$membersByTemplateId = array_fill_keys($idsToPreload, []);
		$memberRows = $this->getMemberRows($idsToPreload);

		$userIds = $userId > 0 ? [$userId => $userId] : [];
		foreach ($memberRows as $row)
		{
			$templateId = (int)$row['TEMPLATE_ID'];
			$memberId = (int)$row['USER_ID'];
			$membersByTemplateId[$templateId][$row['TYPE']][] = $memberId;
			$userIds[$memberId] = $memberId;
		}

		$permissionsByTemplateId = array_fill_keys($idsToPreload, []);
		$permissionRows = $this->getPermissionRows($idsToPreload);

		foreach ($permissionRows as $row)
		{
			$templateId = (int)$row['TEMPLATE_ID'];
			$permissionsByTemplateId[$templateId][$row['ACCESS_CODE']][$row['PERMISSION_ID']] =
				(int)$row['VALUE']
			;
		}

		foreach ($modelsByTemplateId as $templateId => $model)
		{
			$model
				->preloadMembers($membersByTemplateId[$templateId])
				->preloadPermissions($permissionsByTemplateId[$templateId])
			;
		}

		if ($userIds !== [])
		{
			$this->preloadUserDepartments(array_values($userIds));
		}

		foreach ($modelsByTemplateId as $templateId => $model)
		{
			if (TemplateModel::createFromId($templateId) === $model)
			{
				$this->preloadedModelsByUserId[$userId][$templateId] = $model;
			}
		}
	}

	protected function getMemberRows(array $templateIds): array
	{
		return TemplateMemberTable::query()
			->setSelect(['TEMPLATE_ID', 'USER_ID', 'TYPE'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->fetchAll()
		;
	}

	protected function getPermissionRows(array $templateIds): array
	{
		return TasksTemplatePermissionTable::query()
			->setSelect(['TEMPLATE_ID', 'ACCESS_CODE', 'PERMISSION_ID', 'VALUE'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->fetchAll()
		;
	}

	protected function preloadUserDepartments(array $userIds): void
	{
		foreach (array_chunk($userIds, self::USER_DEPARTMENT_PRELOAD_CHUNK_SIZE) as $chunk)
		{
			$this->preloadUserDepartmentsChunk($chunk);
		}
	}

	protected function preloadUserDepartmentsChunk(array $userIds): void
	{
		Container::getInstance()
			->getUserDepartmentsInMemoryFacade()
			->getByUsersIds($userIds)
		;
	}

	private function normalizeIds(array $templateIds): array
	{
		return array_values(
			array_filter(
				array_unique(array_map('intval', $templateIds)),
				static fn (int $templateId): bool => $templateId > 0,
			),
		);
	}
}
