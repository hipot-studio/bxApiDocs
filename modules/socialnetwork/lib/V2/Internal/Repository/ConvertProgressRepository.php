<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertProgress;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;
use Bitrix\Socialnetwork\V2\Infrastructure\Agent\RestoreConvertedProjectListsPermissionAgent;

class ConvertProgressRepository implements ConvertProgressRepositoryInterface
{
	public function markNewConversion(int $groupId): void
	{
		CollabOptionTable::merge(
			[
				'COLLAB_ID' => $groupId,
				'NAME' => RestoreConvertedProjectListsPermissionAgent::NEW_CONVERSION_OPTION_NAME,
				'VALUE' => RestoreConvertedProjectListsPermissionAgent::MARKER_OPTION_VALUE,
			],
			['VALUE' => RestoreConvertedProjectListsPermissionAgent::MARKER_OPTION_VALUE],
			['COLLAB_ID', 'NAME'],
		);
	}

	public function __construct(
		private readonly ConvertProgressMapper $mapper,
	)
	{

	}

	public function getByGroupId(int $groupId): ConvertProgress
	{
		$options = CollabOptionTable::query()
			->setSelect([
				'ID',
				'COLLAB_ID',
				'NAME',
				'VALUE',
			])
			->where('COLLAB_ID', $groupId)
			->whereLike('NAME', ConvertProgressMapper::CONVERT_PREFIX . '%')
			->exec()
			->fetchAll()
		;

		return $this->mapper->mapFromOptions(
			collabId: $groupId,
			options: $options,
		);
	}

	public function getStatusByGroupId(int $groupId): ?ConvertStatus
	{
		$row = CollabOptionTable::query()
			->setSelect(['VALUE'])
			->where('COLLAB_ID', $groupId)
			->where('NAME', ConvertProgressMapper::CONVERT_STATUS)
			->setLimit(1)
			->exec()
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		return ConvertStatus::tryFrom((string)$row['VALUE']);
	}

	public function save(ConvertProgress $progress): void
	{
		$options = $this->mapper->mapToOptions($progress);

		foreach ($options as $option)
		{
			CollabOptionTable::merge(
				insertFields: [
					'COLLAB_ID' => $option['COLLAB_ID'],
					'NAME' => $option['NAME'],
					'VALUE' => $option['VALUE'],
				],
				updateFields: [
					'VALUE' => $option['VALUE'],
				],
				uniqueFields: [
					'COLLAB_ID',
					'NAME',
				],
			);
		}
	}
}
