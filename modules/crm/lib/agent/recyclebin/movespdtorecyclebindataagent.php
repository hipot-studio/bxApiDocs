<?php

namespace Bitrix\Crm\Agent\Recyclebin;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Synchronization\UserFieldSynchronizer;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UserField\Internal\UserFieldHelper;
use CCrmOwnerType;
use CUserTypeEntity;
use Psr\Log\LoggerInterface;
use Bitrix\Recyclebin\Internals\Models\RecyclebinUfTable;

final class MoveSpdToRecycleBinDataAgent extends AgentBase
{
	private const MODULE = 'crm';
	private const DEFAULT_LIMIT_VALUE = 100;
	private const OPTION_DEFAULT_TIME_LIMIT = 10;
	private const OPTION_LIMIT_VALUE = 'spd_to_recycle_bin_option_limit_value';
	private const OPTION_LAST_ID = 'spd_to_recycle_bin_option_last_id';
	private const OPTION_ENTITY_TYPE_ID = 'spd_to_recycle_bin_option_current_entity';
	private const OPTION_TIME_LIMIT = 'spd_to_recycle_bin_option_time_limit';
	private string $lastId;
	private array $entityTypes;
	private ?int $entityTypeId;
	private LoggerInterface $logger;

	public function __construct()
	{
		$this->entityTypes = [
			CCrmOwnerType::Lead,
			CCrmOwnerType::Deal,
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company,
		];
		$this->lastId = Option::get(self::MODULE, self::OPTION_LAST_ID, null) ?? '0';
		$this->entityTypeId = (int)Option::get(self::MODULE, self::OPTION_ENTITY_TYPE_ID, $this->entityTypes[0]);
		$this->logger = Container::getInstance()->getLogger('Default');
	}

	public static function doRun(): bool
	{
		if (!Loader::includeModule('recyclebin'))
		{
			return false;
		}

		return (new self())->execute();
	}

	private function execute(): bool
	{
		$startedTime = time();
		$recycleBinIds = $this->getRecycledIds();

		if (empty($recycleBinIds))
		{
			$nextEntityTypeId = $this->getNextEntity();
			if (!$nextEntityTypeId)
			{
				$this->cleanAfterWork();

				return false;
			}

			$this->unlockRecycleBinStorage();
			$this->entityTypeId = $nextEntityTypeId;

			$this->logger->info('AgentRecycleBinChangeEngine: Next entity type', [
				'entityTypeId' =>$nextEntityTypeId,
			]);

			Option::set(self::MODULE, self::OPTION_ENTITY_TYPE_ID, $this->entityTypeId);
			Option::set(self::MODULE, self::OPTION_LAST_ID, '0');

			return true;
		}

		foreach ($recycleBinIds as $recycleBinId)
		{
			if (time() - $startedTime > $this->getTimeLimit())
			{
				break;
			}

			$fieldsData = $this->getFieldsData($recycleBinId);
			if (!empty($fieldsData))
			{
				$existedRecord = RecyclebinUfTable::query()
					->where('RECYCLEBIN_ID', $recycleBinId)
					->setSelect(['ID'])
					->fetch()
				;
				if (!$existedRecord) // record can exist if entity was deleted after recyclebin api update
				{
					RecyclebinUfTable::add([
						'RECYCLEBIN_ID' => $recycleBinId,
						'UF_ENTITY_ID' => CCrmOwnerType::ResolveUserFieldEntityID($this->entityTypeId),
						'DATA' => $fieldsData,
					]);
				}
			}

			$this->lastId = $recycleBinId;
			Option::set(self::MODULE, self::OPTION_LAST_ID, $this->lastId);
		}

		return true;
	}

	private function getRecycledIds(): array
	{
		$spdEntityName = $this->getSpdEntityName($this->entityTypeId);

		$connection = Application::getConnection();

		$limit = (int)Option::get(self::MODULE, self::OPTION_LIMIT_VALUE, self::DEFAULT_LIMIT_VALUE);
		$utmIds = [];
		if ($connection->isTableExists('b_utm_' . $spdEntityName))
		{
			$utmIds = $this->getRecycleIdsByType('b_utm_' . $spdEntityName, $limit, true);
		}

		$utsIds = [];
		if ($connection->isTableExists('b_uts_' . $spdEntityName))
		{
			$utsIds = $this->getRecycleIdsByType('b_uts_' . $spdEntityName, $limit, false);
		}

		$ids = array_unique(array_merge($utmIds, $utsIds));
		sort($ids, SORT_NUMERIC);

		return array_slice($ids, 0, $limit);
	}

	private function getRecycleIdsByType(string $tableName, int $limit, bool $distinct): array
	{
		$connection = Application::getConnection();
		$sql = sprintf(
			'SELECT %s VALUE_ID FROM %s WHERE VALUE_ID > %d ORDER BY VALUE_ID ASC LIMIT %d',
			$distinct ? 'DISTINCT' : '',
			$connection->getSqlHelper()->quote($tableName),
			(int)$this->lastId,
			$limit,
		);

		return array_column($connection->query($sql)->fetchAll(), 'VALUE_ID');
	}

	private function getNextEntity(): ?int
	{
		$currentEntityKey = array_search($this->entityTypeId, $this->entityTypes, true);

		if ($currentEntityKey === false)
		{
			$this->logger->error('AgentRecycleBinChangeEngine: Entity type not found', [
				'entityTypeId' => $this->entityTypeId,
			]);

			return false;
		}

		return $this->entityTypes[$currentEntityKey + 1] ?? null;
	}

	private function cleanAfterWork(): void
	{
		//Remove Options
		Option::delete(self::MODULE, ['name' => self::OPTION_LIMIT_VALUE]);
		Option::delete(self::MODULE, ['name' => self::OPTION_LAST_ID]);
		Option::delete(self::MODULE, ['name' => self::OPTION_ENTITY_TYPE_ID]);
		Option::delete(self::MODULE, ['name' => self::OPTION_TIME_LIMIT]);

		$this->logger->info('AgentRecycleBinChangeEngine: cleaned after work, options deleted');

		$userTypeEntity = new CUserTypeEntity();
		foreach ($this->entityTypes as $entityTypeId)
		{
			$spdEntityName = $this->getSpdEntityName($entityTypeId, false);
			$spdEntityNameLower = mb_strtolower($spdEntityName);

			$this->truncateTable('b_utm_' . $spdEntityNameLower);
			$this->truncateTable('b_uts_' . $spdEntityNameLower);

			$fields = UserFieldHelper::getInstance()->getManager()?->GetUserFields($spdEntityName);
			foreach ($fields as $field)
			{
				$userTypeEntity->Delete($field['ID']);
				$this->logger->info('AgentRecycleBinChangeEngine: deleted user field', [
					'fieldId' => $field['ID'],
					'fieldName' => $field['FIELD_NAME'],
					'entityId' => $spdEntityName,
				]);
			}
		}

		$this->logger->info('AgentRecycleBinChangeEngine: work completed');
	}

	private function truncateTable(string $tableName): void
	{
		$connection = Application::getConnection();
		if ($connection->isTableExists($tableName))
		{
			$connection->truncateTable($tableName);
		}
	}

	private function unlockRecycleBinStorage(): void
	{
		$entityName = CCrmOwnerType::ResolveName($this->entityTypeId);
		Option::delete(self::MODULE, ['name' => 'CRM_RECYCLE_BIN_USER_FIELDS_STORAGE_UNLOCKED_' . $entityName]);

		$this->logger->info(sprintf('AgentRecycleBinChangeEngine: Complete changes for %s', $entityName), [
			'entityTypeId' => $this->entityTypeId,
			'entityName' => $entityName,
		]);
	}

	private function getSpdEntityName(int $entityTypeId, bool $inLowerCase = true): string
	{
		$name = CCrmOwnerType::ResolveUserFieldEntityID(CCrmOwnerType::ResolveSuspended($entityTypeId));

		return $inLowerCase ? strtolower($name) : $name;
	}

	private function getTimeLimit(): int
	{
		return (int)Option::get(self::MODULE, self::OPTION_TIME_LIMIT, self::OPTION_DEFAULT_TIME_LIMIT);
	}

	private function getFieldsData(int $recycleBinId): array
	{
		$fieldTypeManager = UserFieldHelper::getInstance()->getManager();
		$languageId = Application::getInstance()->getContext()->getLanguage();

		$recycledFields = $fieldTypeManager?->GetUserFields($this->getSpdEntityName($this->entityTypeId, false), $recycleBinId, $languageId);

		$entityFields = $fieldTypeManager?->GetUserFields(\CCrmOwnerType::ResolveUserFieldEntityID($this->entityTypeId), 0, $languageId);

		$intersections = UserFieldSynchronizer::getIntersectionFields($entityFields, $recycledFields);
		if (empty($intersections))
		{
			return [];
		}

		return $this->prepareFieldsData($intersections, $recycledFields);
	}

	private function prepareFieldsData(array $intersections, array $recycledFields): array
	{
		$fieldsData = [];
		foreach ($intersections as $intersection)
		{
			$dstFieldName = $intersection['DST_FIELD_NAME'];

			$restoredField = $recycledFields[$dstFieldName] ?? null;
			if (!is_array($restoredField))
			{
				continue;
			}
			if (
				$restoredField['VALUE'] === null
				|| $restoredField['VALUE'] === ''
				|| $restoredField['VALUE'] === []
				|| $restoredField['VALUE'] === false
			)
			{
				continue;
			}

			$field = [
				'USER_TYPE_ID' => $restoredField['USER_TYPE_ID'],
				'MULTIPLE' => $restoredField['MULTIPLE'],
				'VALUE' => $restoredField['VALUE'],
			];

			$fieldsData[$intersection['SRC_FIELD_NAME']] = $field;
		}

		return $fieldsData;
	}
}
