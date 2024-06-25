<?php

namespace Bitrix\Crm\Agent\Security\DynamicTypes;

use Bitrix\Crm\EntityPermsTable;
use Bitrix\Crm\Security\Controller\DynamicItem;
use Bitrix\Main\Config\Option;
use CCrmOwnerType;

/**
 * Clear entries from the entity permissions table associated with an entity type that has been converted to use atrr tables.
 */
class CleanEntityPermsRecords
{
	public const DONE = false;

	public const CONTINUE = true;

	private const DEFAULT_RM_LIMIT = 100;

	public const CLEAN_LIMIT_OPTION_NAME = 'cleanentitypermsrecords_clean_limit';

	public static function scheduleAgent(int $entityTypeId): void
	{
		$agentName = get_called_class()."::run($entityTypeId);";

		\CAgent::AddAgent(
			$agentName,
			'crm',
			'N',
			60,
		);
	}


	public static function run(int $entityTypeId): string
	{
		return static::doRun($entityTypeId) ? get_called_class()."::run($entityTypeId);" : '';
	}

	public static function doRun(int $entityTypeId): bool
	{
		$instance = new self();
		$result = $instance->execute($entityTypeId);

		if ($result === self::DONE)
		{
			$instance->removeOptions();
		}

		return $result;
	}

	public function execute(int $entityTypeId): bool
	{
		if (!$this->validateEntityTypeId($entityTypeId))
		{
			return self::DONE;
		}

		$ids = $this->getIdsToClean($entityTypeId, $this->getLimit());

		if (empty($ids))
		{
			return self::DONE;
		}

		EntityPermsTable::deleteByIds($ids);

		return self::CONTINUE;
	}

	private function getIdsToClean(int $entityTypeId, int $limit): array
	{
		$entityTypeName = CCrmOwnerType::ResolveName($entityTypeId);

		$rows = EntityPermsTable::query()
			->setSelect(['ID'])
			->whereLike('ENTITY', $entityTypeName . '%')
			->setLimit($limit)
			->fetchAll();

		return array_column($rows, 'ID');
	}

	private function getLimit(): int
	{
		return (int)Option::get('crm', self::CLEAN_LIMIT_OPTION_NAME, self::DEFAULT_RM_LIMIT);
	}

	private function validateEntityTypeId(int $entityTypeId): bool
	{
		return DynamicItem::isSupportedType($entityTypeId);
	}

	public function removeOptions(): void
	{
		Option::delete('crm', ['name' => self::CLEAN_LIMIT_OPTION_NAME]);
	}
}