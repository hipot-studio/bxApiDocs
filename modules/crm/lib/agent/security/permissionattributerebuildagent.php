<?php
namespace Bitrix\Crm\Agent\Security;

use Bitrix\Crm\EntityPermsTable;
use Bitrix\Main\Config\Option;

class PermissionAttributeRebuildAgent extends \Bitrix\Crm\Agent\AgentBase
{
	private const ITERATION_LIMIT = 200;

	private const ENTITY_TYPE_IDS = [
		\CCrmOwnerType::Quote,
		\CCrmOwnerType::Order,
	];

	const LAST_ID_OPTION_NAME = '~CRM_REBUILD_SECURITY_ATTR_LAST_ID';
	const ENTITY_TYPE_ID_OPTION_NAME = '~CRM_REBUILD_SECURITY_ATTR_ENTITY_TYPE_ID';


	public static function doRun(): bool
	{
		return (new self())->execute();
	}


	public function getIterationLimit()
	{
		return (int)Option::get(
			'crm',
			'~CRM_SECURITY_ATTR_REBUILD_STEP_LIMIT',
			self::ITERATION_LIMIT
		);
	}

	public function execute(): bool
	{
		$entityTypeId = $this->getEntityTypeId();
		$itemIds = $this->getItemIds($entityTypeId);

		$permissionEntityType = (new \Bitrix\Crm\Category\PermissionEntityTypeHelper($entityTypeId))->getPermissionEntityTypeForCategory(0);
		$controller = \Bitrix\Crm\Security\Manager::getEntityController($entityTypeId);
		foreach($itemIds as $itemID)
		{
			$controller->register($permissionEntityType, $itemID);
		}

		if (empty($itemIds))
		{
			Option::delete('crm.agent', ['name' => self::LAST_ID_OPTION_NAME]);

			match ($entityTypeId)
			{
				\CCrmOwnerType::Order => \Bitrix\Crm\Security\Controller\Order::enable(),
				\CCrmOwnerType::Quote => \Bitrix\Crm\Security\Controller\Quote::enable(),
			};

			if (!$this->setNextEntityTypeId())
			{
				$entityTypeCodesToClean = array_map(static fn(int $entityTypeId) => \CCrmOwnerType::ResolveName($entityTypeId), self::ENTITY_TYPE_IDS);

				$entityTypeCandidatesToClean = [
					\CCrmOwnerType::LeadName,
					\CCrmOwnerType::CompanyName,
					\CCrmOwnerType::ContactName,
					\CCrmOwnerType::DealName,
				];

				foreach ($entityTypeCandidatesToClean as $entityTypeCandidate)
				{
					if ($this->oldPermissionAttrRecordExists($entityTypeCandidate))
					{
						$entityTypeCodesToClean[] = $entityTypeCandidate;
					}
				}
				Option::set('crm.agent', '~CRM_CLEAN_UNNECESSARY_ENTITY_PERMS_ENTITY_TYPES', implode(',', $entityTypeCodesToClean));

				/** @see \Bitrix\Crm\Agent\Security\CleanUnnecessaryEntityPermsAgent */
				\CAgent::AddAgent(
					'Bitrix\Crm\Agent\Security\CleanUnnecessaryEntityPermsAgent::run();',
					'crm',
					'N',
					60,
				);

				return false;
			}
		}
		else
		{
			Option::set('crm.agent', self::LAST_ID_OPTION_NAME, max($itemIds));
		}

		return true;
	}

	private function getItemIds(int $entityTypeId): array
	{
		$lastId = (int)Option::get('crm.agent', self::LAST_ID_OPTION_NAME, 0);

		$dataClass = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId)->getDataClass();

		return $dataClass::query()
			->setSelect(['ID'])
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->getIterationLimit())
			->exec()
			->fetchCollection()
			->getIdList()
		;
	}

	private function getEntityTypeId(): int
	{
		return (int)Option::get('crm.agent', self::ENTITY_TYPE_ID_OPTION_NAME, self::ENTITY_TYPE_IDS[0]);
	}

	private function setNextEntityTypeId(): bool
	{
		$currentEntityTypeId = $this->getEntityTypeId();
		$currentIndex = array_search($currentEntityTypeId, self::ENTITY_TYPE_IDS, true);
		if ($currentIndex === false || !isset(self::ENTITY_TYPE_IDS[$currentIndex + 1]))
		{
			Option::delete('crm.agent', ['name' => self::ENTITY_TYPE_ID_OPTION_NAME]);

			return false;
		}

		Option::set('crm.agent', self::ENTITY_TYPE_ID_OPTION_NAME, self::ENTITY_TYPE_IDS[$currentIndex + 1]);

		return true;
	}

	private function oldPermissionAttrRecordExists(string $entityType): bool
	{
		return (bool)EntityPermsTable::query()
			->setSelect(['ID'])
			->whereLike('ENTITY', $entityType . '%')
			->setLimit(1)
			->exec()
			->fetch()
		;
	}
}
