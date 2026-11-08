<?php
namespace Bitrix\Crm\Attribute;

use Bitrix\Crm;
use Bitrix\Crm\Attribute\Entity\FieldAttributeTable;
use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use CCrmDeal;
use CCrmOwnerType;

class FieldAttributeManager
{
	protected const CACHE_TTL = 86400;
	private static array $listCache = [];

	/** @var Crm\Restriction\RestrictionManager */
	private static $restrictionManager = Crm\Restriction\RestrictionManager::class;

	public static function isEnabled()
	{
		return true;
	}

	public static function isPhaseDependent()
	{
		return self::$restrictionManager::getAttributeConfigRestriction()->hasPermission();
	}

	public static function isEntitySupported(int $entityTypeId): bool
	{
		if ($entityTypeId === CCrmOwnerType::Requisite || $entityTypeId === CCrmOwnerType::BankDetail)
		{
			return false;
		}
		
		$factory = Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if($factory)
		{
			return $factory->isStagesEnabled();
		}

		return true;
	}

	public static function resolveEntityScope($entityTypeId, $entityId, array $options = null): string
	{
		$entityTypeId = (int)$entityTypeId;
		$entityId = (int)$entityId;

		if (!CCrmOwnerType::IsDefined($entityTypeId))
		{
			throw new Main\ArgumentException(
				'The argument must be valid CCrmOwnerType.',
				'entityTypeID'
			);
		}

		$categoryId = null;
		$factory = Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if ($factory)
		{
			if (is_array($options))
			{
				$categoryId = isset($options['CATEGORY_ID']) ? (int)$options['CATEGORY_ID'] : null;
			}
			if (is_null($categoryId) && $factory->isCategoriesSupported())
			{
				$categoryId = $factory->getItemCategoryId($entityId);

				if (is_null($categoryId))
				{
					$categoryId = $factory->createDefaultCategoryIfNotExist()->getId();
				}
			}
		}
		$scope = static::getEntityScopeByCategory($categoryId);

		if (
			(
				$entityTypeId === CCrmOwnerType::Requisite
				|| $entityTypeId === CCrmOwnerType::BankDetail
			)
			&& is_array($options) && isset($options['PRESET_ID'])
		)
		{
			$presetId = (int)$options['PRESET_ID'];
			$scope .= ($scope !== '' ? '_' : '') . static::getEntityScopeByPreset($presetId);

			if ($entityTypeId === CCrmOwnerType::BankDetail)
			{
				$scope .= '_bd';
			}
		}

		return $scope;
	}

	public static function getEntityScopeByCategory(?int $categoryId = 0): string
	{
		return $categoryId > 0 ? "category_$categoryId" : '';
	}

	public static function getEntityScopeByPreset(?int $presetId = 0): string
	{
		return $presetId > 0 ? "preset_$presetId" : '';
	}

	/**
	 * The very order the runtime sees: {@see \Bitrix\Crm\StatusTable::loadStatusesByEntityId()}.
	 * Phases unfit for it (no positive SORT or ID) are left in the order they came in.
	 */
	protected static function sortPhasesBySortAscAndIdAsc(array $phases): array
	{
		$sortData = [
			'sort' => [],
			'id' => [],
			'keys' => [],
			'phases' => [],
		];

		$isError = false;
		foreach ($phases as $key => $phase)
		{
			if (
				isset($phase['SORT'])
				&& is_numeric($phase['SORT'])
				&& $phase['SORT'] > 0
				&& isset($phase['ID'])
				&& $phase['ID'] > 0
			)
			{
				$sortData['sort'][] = (int)$phase['SORT'];
				$sortData['id'][] = (int)$phase['ID'];
				$sortData['keys'][] = $key;
			}
			else
			{
				$isError = true;
				break;
			}
		}

		if (
			!$isError
			&& array_multisort(
				$sortData['sort'], SORT_ASC, SORT_NUMERIC,
				$sortData['id'], SORT_ASC, SORT_NUMERIC,
				$sortData['keys']
			)
		)
		{
			foreach ($sortData['keys'] as $key)
			{
				$sortData['phases'][$key] = $phases[$key];
			}
			$phases = $sortData['phases'];
		}

		return $phases;
	}

	public static function processPhaseCreation(
		string $phaseID,
		int $entityTypeID,
		string $entityScope,
		array $phases
	): void
	{
		// A new phase inherits nothing. The one exception is a failure phase right behind another
		// failure one: the ranges that ended on its predecessor are cloned onto it.

		if ($phaseID === '')
		{
			return;
		}
		if (!CCrmOwnerType::IsDefined($entityTypeID))
		{
			return;
		}

		$order = static::sortPhasesBySortAscAndIdAsc($phases);
		$sourcePhaseId = PhasePlanner::resolveFailureCloneSource($phaseID, $order);
		if ($sourcePhaseId === null)
		{
			return;
		}

		$rows = FieldAttributeTable::getList(
			[
				'filter' => [
					'=ENTITY_TYPE_ID' => $entityTypeID,
					'=ENTITY_SCOPE' => $entityScope,
					'=TYPE_ID' => FieldAttributeType::REQUIRED,
				],
			]
		)->fetchAll();

		static::applyPhasePlan(PhasePlanner::planCreation($sourcePhaseId, $phaseID, $rows));
	}

	public static function findNeighborPhases(string $phaseID, array $phases = []): array
	{
		return PhasePlanner::neighborsOf($phaseID, $phases);
	}

	public static function processPhaseDeletion($phaseID, $entityTypeID, $entityScope, array $phases = [])
	{
		$phaseID = (string)$phaseID;
		$entityTypeID = (int)$entityTypeID;
		$entityScope = (string)$entityScope;

		// The state of the phases must be before deletion
		$order = static::sortPhasesBySortAscAndIdAsc($phases);

		$rows = FieldAttributeTable::getList(
			[
				'filter' => [
					'=ENTITY_TYPE_ID' => $entityTypeID,
					'=ENTITY_SCOPE' => $entityScope,
					'=TYPE_ID' => FieldAttributeType::REQUIRED,
					[
						'LOGIC' => 'OR',
						'=START_PHASE' => $phaseID,
						'=FINISH_PHASE' => $phaseID,
					]
				],
			]
		)->fetchAll();

		static::applyPhasePlan(PhasePlanner::planDeletion($phaseID, $order, $rows));
	}

	public static function processPhaseSortModification(
		string $phaseId,
		int    $phaseSort,
		int    $entityTypeID,
		string $entityScope,
		array  $phases
	)
	{
		if (!isset($phases[$phaseId]) || $phaseSort === (int)$phases[$phaseId]['SORT'])
		{
			return;
		}

		$oldOrder = static::sortPhasesBySortAscAndIdAsc($phases);

		$newPhases = $phases;
		$newPhases[$phaseId]['SORT'] = $phaseSort;
		$newOrder = static::sortPhasesBySortAscAndIdAsc($newPhases);
		if (array_keys($oldOrder) === array_keys($newOrder))
		{
			// The new weight leaves the phases where they were: the plan would be empty whatever the
			// rows say, so the whole settings scope is left unread.
			return;
		}

		$rows = FieldAttributeTable::getList(
			[
				'filter' => [
					'=ENTITY_TYPE_ID' => $entityTypeID,
					'=ENTITY_SCOPE' => $entityScope,
					'=TYPE_ID' => FieldAttributeType::REQUIRED,
				],
			]
		)->fetchAll();

		static::applyPhasePlan(PhasePlanner::planReorderScope($oldOrder, $newOrder, $rows));
	}

	/**
	 * Brings the rows to the plan {@see \Bitrix\Crm\Attribute\PhasePlanner} has drawn. Updates come
	 * first and deletions last, so that no intermediate state of the scope looks like a lost setting.
	 */
	private static function applyPhasePlan(array $plan): void
	{
		foreach ($plan['update'] as $modification)
		{
			FieldAttributeTable::update(
				$modification['ID'],
				[
					'CREATED_TIME' => new Main\Type\DateTime(),
					'START_PHASE' => $modification['START_PHASE'],
					'FINISH_PHASE' => $modification['FINISH_PHASE'],
				]
			);
		}

		foreach ($plan['add'] as $addition)
		{
			$record = $addition['ROW'];
			unset($record['ID']);
			$record['CREATED_TIME'] = new Main\Type\DateTime();
			$record['START_PHASE'] = $addition['START_PHASE'];
			$record['FINISH_PHASE'] = $addition['FINISH_PHASE'];

			FieldAttributeTable::add($record);
		}

		foreach ($plan['delete'] as $id)
		{
			FieldAttributeTable::delete($id);
		}
	}

	/**
	 * @deprecated Does nothing: an inverted range is no longer a reason to drop a user setting.
	 */
	public static function processPhaseModification($phaseID, $entityTypeID, $entityScope, array $phases)
	{
	}

	public static function getEntityConfigurations($entityTypeID, $entityScope)
	{
		if(!CCrmOwnerType::IsDefined($entityTypeID))
		{
			throw new Main\ArgumentException(
				'The argument must be valid CCrmOwnerType.',
				'entityTypeID'
			);
		}

		$results = self::getFieldAttributes($entityTypeID, $entityScope);

		$configs = array();
		foreach ($results as $fieldName => $fieldData)
		{
			$configs[$fieldName] = array();
			foreach ($fieldData as $typeID => $typeData)
			{
				$config = [
					'typeId' => $typeID,
					'groups' => array()
				];
				foreach ($typeData as $phaseGroupTypeID => $phaseGroupTypeData)
				{
					$config['groups'][] = array(
						'phaseGroupTypeId' => $phaseGroupTypeID,
						'items' => $phaseGroupTypeData
					);
				}
				$configs[$fieldName][] = $config;
			}
		}

		return $configs;
	}

	private static function getFieldAttributes(int $entityTypeID, string $entityScope):array
	{
		$query = new Main\Entity\Query(FieldAttributeTable::getEntity());
		//$query->addSelect('ID');
		$query->addSelect('TYPE_ID');
		//$query->addSelect('IS_CUSTOM_FIELD');
		$query->addSelect('FIELD_NAME');
		$query->addSelect('START_PHASE');
		$query->addSelect('FINISH_PHASE');
		$query->addSelect('PHASE_GROUP_TYPE_ID');

		$query->addFilter('=ENTITY_TYPE_ID', $entityTypeID);
		$query->addFilter('=ENTITY_SCOPE', $entityScope);

		$results = array();
		$dbResult = $query->exec();
		while($fields = $dbResult->fetch())
		{
			$fieldName = $fields['FIELD_NAME'];
			$typeID = $fields['TYPE_ID'];
			$phaseGroupTypeID = $fields['PHASE_GROUP_TYPE_ID'];

			if(!isset($results[$fieldName]))
			{
				$results[$fieldName] = array();
			}

			if(!isset($results[$fieldName][$typeID]))
			{
				$results[$fieldName][$typeID] = array();
			}

			if(!isset($results[$fieldName][$typeID][$phaseGroupTypeID]))
			{
				$results[$fieldName][$typeID][$phaseGroupTypeID] = array();
			}

			$results[$fieldName][$typeID][$phaseGroupTypeID][] = array(
				'startPhaseId' => $fields['START_PHASE'],
				'finishPhaseId' => $fields['FINISH_PHASE']
			);
		}
		return $results;
	}
	public static function saveEntityConfiguration(array $config, $fieldName, $entityTypeID, $entityScope)
	{
		if($fieldName === '')
		{
			return;
		}

		$typeID = isset($config['typeId']) ? (int)$config['typeId'] : FieldAttributeType::REQUIRED;
		self::removeEntityConfiguration($typeID, $fieldName, $entityTypeID, $entityScope);

		$groups = isset($config['groups']) && is_array($config['groups']) ? $config['groups'] : array();
		if(empty($groups))
		{
			return;
		}

		foreach($groups as $group)
		{
			$phaseGroupTypeID = isset($group['phaseGroupTypeId'])
				? (int)$group['phaseGroupTypeId'] : FieldAttributePhaseGroupType::UNDEFINED;

			if(!FieldAttributePhaseGroupType::isDefined($phaseGroupTypeID))
			{
				continue;
			}

			if($phaseGroupTypeID === FieldAttributePhaseGroupType::ALL)
			{
				Entity\FieldAttributeTable::add(
					array(
						'ENTITY_TYPE_ID' => $entityTypeID,
						'ENTITY_SCOPE' => $entityScope,
						'TYPE_ID' => $typeID,
						'FIELD_NAME' => $fieldName,
						'CREATED_TIME' => new Main\Type\DateTime(),
						'START_PHASE' => '',
						'FINISH_PHASE' => '',
						'PHASE_GROUP_TYPE_ID' => FieldAttributePhaseGroupType::ALL,
						'IS_CUSTOM_FIELD' => (mb_strpos($fieldName, 'UF_') === 0) ? 'Y' : 'N'
					)
				);
				break;
			}

			$items = isset($group['items']) && is_array($group['items']) ? $group['items'] : array();
			foreach($items as $item)
			{
				$startPhaseID = isset($item['startPhaseId']) ? $item['startPhaseId'] : '';
				$finishPhaseID = isset($item['finishPhaseId']) ? $item['finishPhaseId'] : '';

				if($startPhaseID === '' || $finishPhaseID === '')
				{
					continue;
				}

				Entity\FieldAttributeTable::add(
					array(
						'ENTITY_TYPE_ID' => $entityTypeID,
						'ENTITY_SCOPE' => $entityScope,
						'TYPE_ID' => $typeID,
						'FIELD_NAME' => $fieldName,
						'CREATED_TIME' => new Main\Type\DateTime(),
						'START_PHASE' => $startPhaseID,
						'FINISH_PHASE' => $finishPhaseID,
						'PHASE_GROUP_TYPE_ID' => $phaseGroupTypeID,
						'IS_CUSTOM_FIELD' => (mb_strpos($fieldName, 'UF_') === 0) ? 'Y' : 'N'
					)
				);
			}
		}
	}
	public static function removeEntityConfiguration($typeID, $fieldName, $entityTypeID, $entityScope = '')
	{
		if(!is_int($typeID))
		{
			$typeID = (int)$typeID;
		}

		if(!FieldAttributeType::isDefined($typeID))
		{
			return;
		}

		$filter = [
			'=ENTITY_TYPE_ID' => $entityTypeID,
			'=ENTITY_SCOPE' => $entityScope,
			'=TYPE_ID' => $typeID,
			'=FIELD_NAME' => $fieldName,
		];
		self::delete($filter);
	}

	public static function getRequiredFields(
		$entityTypeID,
		$entityID,
		array $entityData,
		$fieldOrigin = 0,
		array $options = null
	)
	{
		if (!is_int($entityTypeID))
		{
			$entityTypeID = (int)$entityTypeID;
		}

		if (!CCrmOwnerType::IsDefined($entityTypeID))
		{
			throw new Main\ArgumentException(
				'The argument must be valid CCrmOwnerType.',
				'entityTypeID'
			);
		}

		if (!is_array($options))
		{
			$options = [];
		}

		$entityScope = '';
		if ($entityTypeID === CCrmOwnerType::Deal)
		{
			$categoryID = isset($entityData['CATEGORY_ID']) ? (int)$entityData['CATEGORY_ID'] : -1;
			$stageID = isset($entityData['STAGE_ID']) ? $entityData['STAGE_ID'] : '';
			if ($categoryID < 0 || $stageID === '')
			{
				if ($stageID === '')
				{
					if ($entityID > 0)
					{
						$dbResult = CCrmDeal::GetListEx(
							[],
							['=ID' => $entityID, 'CHECK_PERMISSIONS' => 'N'],
							false,
							false,
							['ID', 'CATEGORY_ID', 'STAGE_ID']
						);

						$fields = is_object($dbResult) ? $dbResult->Fetch() : null;
						if (!$fields)
						{
							return [];
						}

						$categoryID = isset($fields['CATEGORY_ID']) ? (int)$fields['CATEGORY_ID'] : 0;
						$stageID = isset($fields['STAGE_ID']) ? $fields['STAGE_ID'] : '';
					}

					if ($stageID === '')
					{
						$stageID = CCrmDeal::GetStartStageID($categoryID);
					}
				}
				elseif ($categoryID < 0)
				{
					$categoryID = Crm\Category\DealCategory::resolveFromStageID($stageID);
				}

				$entityData['CATEGORY_ID'] = $categoryID;
				$entityData['STAGE_ID'] = $stageID;
			}

			$entityScope = self::resolveEntityScope(
				CCrmOwnerType::Deal,
				$entityID,
				['CATEGORY_ID' => $categoryID]
			);

			if (!isset($options['CATEGORY_ID']))
			{
				$options['CATEGORY_ID'] = $categoryID;
			}
		}
		elseif (in_array($entityTypeID, [CCrmOwnerType::Company, CCrmOwnerType::Contact]))
		{
			if (isset($options['CATEGORY_ID']))
			{
				$categoryID = $options['CATEGORY_ID'];
			}
			else
			{
				$categoryID = (int)($entityData['CATEGORY_ID'] ?? 0);
				$options['CATEGORY_ID'] = $categoryID;
			}

			$entityScope = self::resolveEntityScope($entityTypeID, $entityID, ['CATEGORY_ID' => $categoryID]);
		}
		elseif (
			$entityTypeID === CCrmOwnerType::Requisite
			|| $entityTypeID === CCrmOwnerType::BankDetail
		)
		{
			$presetId = (int)($options['PRESET_ID'] ?? 0);

			if ($presetId <= 0)
			{
				if ($entityTypeID === CCrmOwnerType::Requisite)
				{
					$presetId = EntityRequisite::getSingleInstance()->getPresetIdByFields($entityID, $entityData);
				}
				else //if ($entityTypeID === CCrmOwnerType::BankDetail)
				{
					$presetId = EntityBankDetail::getSingleInstance()->getPresetIdByFields($entityID, $entityData);
				}
			}

			if ($presetId <= 0)
			{
				throw new Main\SystemException(
					'The presetId must be defined for the Requisite or BankDetail entity types'
				);
			}

			$entityScope = self::resolveEntityScope($entityTypeID, $entityID, ['PRESET_ID' => $presetId]);
		}

		$fieldsData = static::getList($entityTypeID, $entityScope, $fieldOrigin);
		$result = [];
		foreach ($fieldsData as $fields)
		{
			if (
				!self::checkPhaseCondition(
					$entityTypeID,
					$entityData,
					$fields['START_PHASE'],
					$fields['FINISH_PHASE'],
					$options
				)
			)
			{
				continue;
			}

			if ($fieldOrigin !== FieldOrigin::UNDEFINED)
			{
				$result[] = $fields['FIELD_NAME'];
			}
			else
			{
				$key = $fields['IS_CUSTOM_FIELD'] === 'Y' ? FieldOrigin::CUSTOM : FieldOrigin::SYSTEM;
				if (!isset($result[$key]))
				{
					$result[$key] = array();
				}
				$result[$key][] = $fields['FIELD_NAME'];
			}
		}

		$resultCustom = $result[FieldOrigin::CUSTOM] ?? null;
		if (is_array($resultCustom))
		{
			$notAccessibleFields = VisibilityManager::getNotAccessibleFields($entityTypeID);
			$result[FieldOrigin::CUSTOM] =  array_diff($result[FieldOrigin::CUSTOM], $notAccessibleFields);
		}

		return $result;
	}

	/** @deprecated  */
	public static function getRequiredUserFields($entityTypeID, $entityID, array $entityData)
	{
		return self::getRequiredFields($entityTypeID, $entityID, $entityData, FieldOrigin::CUSTOM);
	}

	/** @deprecated  */
	public static function getRequiredSystemFields($entityTypeID, $entityID, array $entityData)
	{
		return self::getRequiredFields($entityTypeID, $entityID, $entityData, FieldOrigin::SYSTEM);
	}

	public static function onUserFieldDelete(array $fields, $ID)
	{
		$fieldName = isset($fields['FIELD_NAME']) ? $fields['FIELD_NAME'] : '';
		if($fieldName === '')
		{
			return;
		}

		$ufEntityId = $fields['ENTITY_ID'] ?? '';
		$entityTypeId = CCrmOwnerType::ResolveIDByUFEntityID($ufEntityId);
		if (!$entityTypeId)
		{
			return;
		}

		$filter = [
			'=FIELD_NAME' => $fieldName,
			'=ENTITY_TYPE_ID' => $entityTypeId,
		];
		self::delete($filter);
	}

	public static function onUserFieldUpdate(array $fields, $ID)
	{
		if($ID <= 0)
		{
			return;
		}

		$allFields = \CUserTypeEntity::GetByID($ID);
		if(!is_array($allFields))
		{
			return;
		}

		$fields = array_merge($allFields, $fields);

		$fieldName = isset($fields['FIELD_NAME']) ? $fields['FIELD_NAME'] : '';
		if($fieldName === '')
		{
			return;
		}

		if(isset($fields['MANDATORY']) && $fields['MANDATORY'] === 'Y')
		{
			$filter = [
				'=FIELD_NAME' => $fieldName,
				'=TYPE_ID' => FieldAttributeType::REQUIRED,
			];
			self::delete($filter);
		}
	}

	protected static function checkPhaseCondition(
		$entityTypeID,
		array $entityData,
		$startPhase,
		$finishPhase,
		array $options = null
	)
	{
		//If Start Phase and Finish Phase are empty, then field is required always.
		if ($startPhase === '' && $finishPhase === '')
		{
			return true;
		}

		if (!is_array($options))
		{
			$options = [];
		}

		if ($entityTypeID === CCrmOwnerType::Deal)
		{
			$categoryID = (int)($entityData['CATEGORY_ID'] ?? -1);
			if ($categoryID < 0 && isset($options['CATEGORY_ID']))
			{
				$categoryID = $options['CATEGORY_ID'];
			}

			$startStageSort = CCrmDeal::GetStageSort($startPhase, $categoryID);
			$finishStageSort = CCrmDeal::GetStageSort($finishPhase, $categoryID);

			$stageID = $options['STAGE_ID'] ?? ($entityData['STAGE_ID'] ?? '');
			$stageSort = CCrmDeal::GetStageSort($stageID, $categoryID);

			return ($stageSort >= $startStageSort && $stageSort <= $finishStageSort);
		}
		if ($entityTypeID === CCrmOwnerType::Lead)
		{
			$startStatusSort = \CCrmLead::GetStatusSort($startPhase);
			$finishStatusSort = \CCrmLead::GetStatusSort($finishPhase);

			$statusID = $options['STATUS_ID'] ?? ($entityData['STATUS_ID'] ?? '');
			$statusSort = \CCrmLead::GetStatusSort($statusID);

			return ($statusSort >= $startStatusSort && $statusSort <= $finishStatusSort);
		}
		if ($entityTypeID === CCrmOwnerType::Contact)
		{
			// There are no statuses for contacts yet
			return true;
		}
		if ($entityTypeID === CCrmOwnerType::Company)
		{
			// There are no statuses for companies yet
			return true;
		}
		if ($entityTypeID === CCrmOwnerType::Requisite)
		{
			// There are no statuses for requisites yet
			return true;
		}
		if ($entityTypeID === CCrmOwnerType::BankDetail)
		{
			// There are no statuses for requisites yet
			return true;
		}

		return false;
	}

	/**
	 * Return phrases for entity editor field configurator (BX.Crm.EntityFieldAttributeConfigurator)
	 *
	 * @return array
	 */
	public static function getCaptionsForEntityWithStages(int $entityTypeId = CCrmOwnerType::Undefined): array
	{
		// for stages and categories by default
		$captions = [
			'REQUIRED_SHORT' => Loc::getMessage('CRM_FIELD_ATTRIBUTE_MANAGER_STAGE_CAPTION_REQUIRED_SHORT'),
			'REQUIRED_FULL' => Loc::getMessage('CRM_FIELD_ATTRIBUTE_MANAGER_STAGE_CAPTION_REQUIRED_FULL_1'),
			'GROUP_TYPE_GENERAL' => Loc::getMessage('CRM_FIELD_ATTRIBUTE_MANAGER_STAGE_CAPTION_GROUP_TYPE_GENERAL2'),
			'GROUP_TYPE_PIPELINE' => Loc::getMessage('CRM_FIELD_ATTRIBUTE_MANAGER_STAGE_CAPTION_GROUP_TYPE_PIPELINE'),
			'GROUP_TYPE_JUNK' => Loc::getMessage('CRM_FIELD_ATTRIBUTE_MANAGER_STAGE_CAPTION_GROUP_TYPE_JUNK'),
		];

		if ($entityTypeId === CCrmOwnerType::Deal)
		{
			return $captions;
		}

		$captionsForNotCategorized = [
			'REQUIRED_SHORT' => Loc::getMessage('CRM_FIELD_ATTRIBUTE_MANAGER_STATUS_CAPTION_REQUIRED_SHORT'),
			'REQUIRED_FULL' => Loc::getMessage('CRM_FIELD_ATTRIBUTE_MANAGER_STATUS_CAPTION_REQUIRED_SHORT'),
			'GROUP_TYPE_GENERAL' => '',
			'GROUP_TYPE_PIPELINE' => '',
			'GROUP_TYPE_JUNK' => '',
		];

		if (
			$entityTypeId === CCrmOwnerType::Requisite
			|| $entityTypeId === CCrmOwnerType::BankDetail
		)
		{
			return $captionsForNotCategorized;
		}

		$factory = Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if ($factory)
		{
			if ($factory->isStagesEnabled())
			{
				if (!$factory->isCategoriesEnabled())
				{
					$captions = [
						'REQUIRED_SHORT' => Loc::getMessage(
							'CRM_FIELD_ATTRIBUTE_MANAGER_STATUS_CAPTION_REQUIRED_SHORT'
						),
						'REQUIRED_FULL' => Loc::getMessage(
							'CRM_FIELD_ATTRIBUTE_MANAGER_STAGE_CAPTION_REQUIRED_FULL_1'
						),
						'GROUP_TYPE_GENERAL' => Loc::getMessage(
							'CRM_FIELD_ATTRIBUTE_MANAGER_STATUS_CAPTION_GROUP_TYPE_GENERAL_MSGVER_1'
						),
						'GROUP_TYPE_PIPELINE' => Loc::getMessage(
							'CRM_FIELD_ATTRIBUTE_MANAGER_STATUS_CAPTION_GROUP_TYPE_PIPELINE'
						),
						'GROUP_TYPE_JUNK' => Loc::getMessage(
							'CRM_FIELD_ATTRIBUTE_MANAGER_STATUS_CAPTION_GROUP_TYPE_JUNK'
						),
					];
				}
			}
			else
			{
				$captions = $captionsForNotCategorized;
			}
		}

		return $captions;
	}

	/**
	 * Adds information about attributes config from $attrConfigs to $fieldInfos.
	 * Returns array of fieldNames that required by this config.
	 *
	 * @param array $attrConfigs Config to get information from.
	 * @param array $fieldInfos Field parameters to add information to.
	 * @return string[]
	 */
	public static function prepareEditorFieldInfosWithAttributes(array $attrConfigs, array &$fieldInfos): array
	{
		$requiredByAttributeFieldNames = [];

		$isPhaseDependent = static::isPhaseDependent();
		for ($i = 0, $length = count($fieldInfos); $i < $length; $i++)
		{
			if (!$isPhaseDependent)
			{
				$fieldInfos[$i]['data']['isPhaseDependent'] = false;
			}

			$fieldName = $fieldInfos[$i]['name'];
			$attrConfigsValue = $attrConfigs[$fieldName] ?? null;
			if(!is_array($attrConfigsValue) || empty($attrConfigsValue))
			{
				continue;
			}

			$fieldInfos[$i]['data']['attrConfigs'] = $attrConfigs[$fieldName];

			$isRequiredByAttribute = false;
			$ready = false;
			$attrConfig = $attrConfigs[$fieldName];
			foreach ($attrConfig as $item)
			{
				if (
					is_array($item)
					&& isset($item['typeId'])
					&& $item['typeId'] === FieldAttributeType::REQUIRED
				)
				{
					if ($isPhaseDependent)
					{
						if (is_array($item['groups']))
						{
							foreach ($item['groups'] as $group)
							{
								if (is_array($group) && isset($group['phaseGroupTypeId'])
									&& $group['phaseGroupTypeId'] === FieldAttributePhaseGroupType::ALL)
								{
									$isRequiredByAttribute = true;
									$ready = true;
									break;
								}
							}
						}
					}
					else
					{
						$isRequiredByAttribute = true;
						$ready = true;
					}
					if ($ready)
					{
						break;
					}
				}
			}
			if ($isRequiredByAttribute)
			{
				$fieldInfos[$i]['data']['isRequiredByAttribute'] = true;
				$requiredByAttributeFieldNames[] = $fieldName;
			}
		}

		return $requiredByAttributeFieldNames;
	}

	/**
	 * Return scope for $item.
	 *
	 * @param Crm\Item $item
	 * @return string
	 */
	public static function getItemConfigScope(Crm\Item $item): string
	{
		return static::resolveEntityScope(
			$item->getEntityTypeID(),
			$item->getId(),
			[
				'CATEGORY_ID' => $item->getCategoryId(),
			]
		);
	}

	/**
	 * Returns data from FieldAttributeTable.
	 *
	 * @param int $entityTypeId
	 * @param string $entityScope
	 * @param int|null $fieldOrigin
	 * @param int|null $typeId
	 * @return array
	 */
	public static function getList(
		int $entityTypeId,
		string $entityScope,
		?int $fieldOrigin = FieldOrigin::UNDEFINED,
		?int $typeId = FieldAttributeType::REQUIRED
	): array
	{
		$fieldOriginKey = $fieldOrigin === null ? 'origin:null' : "origin:{$fieldOrigin}";
		$typeIdKey = $typeId === null ? 'type:null' : "type:{$typeId}";
		if (!isset(self::$listCache[$entityTypeId][$entityScope][$fieldOriginKey][$typeIdKey]))
		{
			$query = new Main\Entity\Query(Entity\FieldAttributeTable::getEntity());
			$query->addSelect('ENTITY_TYPE_ID');
			$query->addSelect('FIELD_NAME');
			$query->addSelect('START_PHASE');
			$query->addSelect('FINISH_PHASE');
			$query->addSelect('IS_CUSTOM_FIELD');

			$query->addFilter('=ENTITY_TYPE_ID', $entityTypeId);
			$query->addFilter('=ENTITY_SCOPE', $entityScope);
			if($fieldOrigin > 0)
			{
				$query->addFilter('=IS_CUSTOM_FIELD', $fieldOrigin === FieldOrigin::CUSTOM ? 'Y' : 'N');
			}
			if($typeId > 0)
			{
				$query->addFilter('=TYPE_ID', $typeId);
			}

			$query->setCacheTtl(self::CACHE_TTL);

			self::$listCache[$entityTypeId][$entityScope][$fieldOriginKey][$typeIdKey] = $query->exec()->fetchAll();
		}

		return self::$listCache[$entityTypeId][$entityScope][$fieldOriginKey][$typeIdKey];
	}

	public static function invalidateRuntimeCache(int $entityTypeId, string $entityScope): void
	{
		unset(self::$listCache[$entityTypeId][$entityScope]);
		if ((self::$listCache[$entityTypeId] ?? []) === [])
		{
			unset(self::$listCache[$entityTypeId]);
		}
	}

	/**
	 * Returns a flat list of field names that are always required, on any stage
	 *
	 * @param array $fieldsData - Data that returns by self::getList() method.
	 * @return string[] - Flat list of required field names
	 */
	final public static function extractNamesOfAlwaysRequiredFields(array $fieldsData): array
	{
		$result = [];

		if (empty($fieldsData))
		{
			return $result;
		}

		foreach ($fieldsData as $fieldConfig)
		{
			//If Start Phase and Finish Phase are empty, then field is required always.
			if (empty($fieldConfig['START_PHASE']) && empty($fieldConfig['FINISH_PHASE']))
			{
				$result[] = $fieldConfig['FIELD_NAME'];
			}
		}

		return $result;
	}

	/**
	 * Return list of field names that matches specified configs and stages.
	 *
	 * @param array $fieldsData - Data that returns by self::getList() method.
	 * @param Crm\EO_Status_Collection $collection - Stages collection for which settings should be processed.
	 * @param string $currentStageId - Current stage identifier.
	 * @return string[] - Flat array of required field names
	 */
	public static function processFieldsForStages(
		array $fieldsData,
		Crm\EO_Status_Collection $collection,
		string $currentStageId
	): array
	{
		if (!static::isPhaseDependent())
		{
			return [];
		}
		if(empty($fieldsData))
		{
			return [];
		}

		$stages = [];
		foreach ($collection as $stage)
		{
			$stages[$stage->getStatusId()] = $stage;
		}
		$currentStageSort = isset($stages[$currentStageId]) ? $stages[$currentStageId]->getSort() : -1;

		$result = static::extractNamesOfAlwaysRequiredFields($fieldsData);

		foreach ($fieldsData as $fieldConfig)
		{
			if (empty($fieldConfig['START_PHASE']) && empty($fieldConfig['FINISH_PHASE']))
			{
				continue;
			}

			$startStageSort =
				isset($stages[$fieldConfig['START_PHASE']])
				? $stages[$fieldConfig['START_PHASE']]->getSort()
				: -1
			;
			$finishStageSort =
				isset($stages[$fieldConfig['FINISH_PHASE']])
				? $stages[$fieldConfig['FINISH_PHASE']]->getSort()
				: -1
			;

			if ($currentStageSort >= $startStageSort && $currentStageSort <= $finishStageSort)
			{
				$result[] = $fieldConfig['FIELD_NAME'];
			}
		}

		return array_unique($result);
	}

	public static function deleteByOwnerType(int $entityTypeId)
	{
		$filter = [
			'=ENTITY_TYPE_ID' => $entityTypeId,
		];
		self::delete($filter);
	}

	public static function deleteByScopePrefix(string $scopePrefix, array $excludeFields = []): void
	{
		if ($scopePrefix !== '')
		{
			$filter = ['=%ENTITY_SCOPE' => "$scopePrefix%"];

			if (!empty($excludeFields))
			{
				 $filter['!@FIELD_NAME'] = $excludeFields;
			}

			static::delete($filter);
		}
	}

	public static function deleteByScope(string $scope, array $excludeFields = []): void
	{
		if ($scope !== '')
		{
			$filter = ['=ENTITY_SCOPE' => $scope];

			if (!empty($excludeFields))
			{
				 $filter['!@FIELD_NAME'] = $excludeFields;
			}

			static::delete($filter);
		}
	}

	/**
	 * @param array $filter
	 */
	protected static function delete(array $filter): void
	{
		$res = FieldAttributeTable::getList([
			'filter' => $filter,
			'select' => [
				'ID',
			],
		]);
		while($item = $res->fetch())
		{
			FieldAttributeTable::delete($item['ID']);
		}
	}
}
