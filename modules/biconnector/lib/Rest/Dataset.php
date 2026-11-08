<?php

namespace Bitrix\BIConnector\Rest;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Access\Table\TableAccessChecker;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceDatasetRelationTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestConnectorTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\ExternalSource\RestDatasetManager;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;
use Bitrix\BIConnector\ExternalSource\Type;

if (!Loader::includeModule('rest'))
{
	return;
}

class Dataset extends Base
{
	public const SCOPE = 'biconnector';
	private static bool $useOldBehavior = false;

	public static function OnRestServiceBuildDescription()
	{
		return [
			self::SCOPE => [
				'biconnector.table.add' => [
					'callback' => [__CLASS__, 'add'],
					'options' => [],
				],
				'biconnector.table.update' => [
					'callback' => [__CLASS__, 'update'],
					'options' => [],
				],
				'biconnector.table.delete' => [
					'callback' => [__CLASS__, 'delete'],
					'options' => [],
				],
				'biconnector.table.fields' => [
					'callback' => [__CLASS__, 'fields'],
					'options' => [],
				],
				'biconnector.table.get' => [
					'callback' => [__CLASS__, 'get'],
					'options' => [],
				],
				'biconnector.table.list' => [
					'callback' => [__CLASS__, 'list'],
					'options' => [],
				],
				'biconnector.table.fields.update' => [
					'callback' => [__CLASS__, 'fieldsUpdate'],
					'options' => [],
				],
				/*
				 * TODO: remove in future releases
				 * biconnector.dataset.* methods are deprecated and will be removed in future releases. Use biconnector.table.* methods instead.
				 */
				'biconnector.dataset.add' => [
					'callback' => [__CLASS__, 'addOldBehavior'],
					'options' => [],
				],
				'biconnector.dataset.update' => [
					'callback' => [__CLASS__, 'update'],
					'options' => [],
				],
				'biconnector.dataset.delete' => [
					'callback' => [__CLASS__, 'deleteOldBehavior'],
					'options' => [],
				],
				'biconnector.dataset.fields' => [
					'callback' => [__CLASS__, 'fields'],
					'options' => [],
				],
				'biconnector.dataset.get' => [
					'callback' => [__CLASS__, 'get'],
					'options' => [],
				],
				'biconnector.dataset.list' => [
					'callback' => [__CLASS__, 'list'],
					'options' => [],
				],
				'biconnector.dataset.fields.update' => [
					'callback' => [__CLASS__, 'fieldsUpdate'],
					'options' => [],
				],
			]
		];
	}

	public static function add(array $params, $n, \CRestServer $server): array
	{
		return parent::add(self::neutralizeIgnoredTableGroupIds($params), $n, $server);
	}

	public static function update(array $params, $n, \CRestServer $server): array|bool
	{
		return parent::update(self::neutralizeIgnoredTableGroupIds($params), $n, $server);
	}

	/**
	 * While the table axis is off the groups are accepted and ignored, so their value is not checked at all.
	 * The field itself is kept: an update carrying nothing else must still pass as before.
	 */
	private static function neutralizeIgnoredTableGroupIds(array $params): array
	{
		if (
			is_array($params['fields'] ?? null)
			&& array_key_exists('tableGroupIds', $params['fields'])
			&& !(new TableAccessChecker())->isRestricted()
		)
		{
			$params['fields']['tableGroupIds'] = [];
		}

		return $params;
	}

	protected static function getFields(): array
	{
		return [
			[
				'title' => 'id',
				'type' => 'integer',
				'isRequired' => true,
				'isReadOnly' => true,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'sourceId',
				'type' => 'integer',
				'isRequired' => true,
				'isReadOnly' => false,
				'isImmutable' => true,
				'isMultiple' => false,
			],
			[
				'title' => 'name',
				'type' => 'string',
				'isRequired' => true,
				'isReadOnly' => false,
				'isImmutable' => true,
				'isMultiple' => false,
			],
			[
				'title' => 'type',
				'type' => 'string',
				'isRequired' => true,
				'isReadOnly' => true,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'description',
				'type' => 'string',
				'isRequired' => false,
				'isReadOnly' => false,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'externalName',
				'type' => 'string',
				'isRequired' => true,
				'isReadOnly' => false,
				'isImmutable' => true,
				'isMultiple' => false,
			],
			[
				'title' => 'externalCode',
				'type' => 'string',
				'isRequired' => true,
				'isReadOnly' => false,
				'isImmutable' => true,
				'isMultiple' => false,
			],
			[
				'title' => 'externalId',
				'type' => 'integer',
				'isRequired' => true,
				'isReadOnly' => true,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'dateCreate',
				'type' => 'datetime',
				'isRequired' => true,
				'isReadOnly' => true,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'dateUpdate',
				'type' => 'datetime',
				'isRequired' => true,
				'isReadOnly' => true,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'createdById',
				'type' => 'integer',
				'isRequired' => true,
				'isReadOnly' => true,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'updatedById',
				'type' => 'integer',
				'isRequired' => true,
				'isReadOnly' => true,
				'isImmutable' => false,
				'isMultiple' => false,
			],
			[
				'title' => 'fields',
				'type' => 'array',
				'isRequired' => true,
				'isReadOnly' => false,
				'isImmutable' => false,
				'isMultiple' => true,
			],
			[
				'title' => 'tableGroupIds',
				'type' => 'array',
				'isRequired' => false,
				'isReadOnly' => false,
				'isImmutable' => false,
				'isMultiple' => true,
			],
		];
	}

	protected static function getListExcludedFields(): array
	{
		return ['tableGroupIds'];
	}

	protected static function addEntity(array $params): Result
	{
		$converter = new Converter(
			Converter::KEYS
			| Converter::RECURSIVE
			| Converter::TO_SNAKE
			| Converter::TO_UPPER
		);
		$fields = $converter->process($params['fields']);
		$extractResult = self::extractTableGroupIds($fields);
		if (!$extractResult->isSuccess())
		{
			return $extractResult;
		}
		$tableGroupIds = $extractResult->getData()['tableGroupIds'];
		$fields['TYPE'] = Type::Rest->value;

		$datasetFields = $fields['FIELDS'];
		unset($fields['FIELDS']);
		$sourceId = $fields['SOURCE_ID'];
		unset($fields['SOURCE_ID']);
		$dataset = $fields;

		if (!self::checkDatasetName($dataset['NAME']))
		{
			$result = new Result();
			$result->addError(new Error(
				'Dataset name has to start with a lowercase Latin character. Possible entry includes lowercase Latin characters (a-z), numbers (0-9) and underscores.',
				'VALIDATION_DATASET_NAME_INVALID'
			));

			return $result;
		}

		$source = ExternalSourceTable::getById($sourceId)->fetchObject();
		if (
			!$source
			|| $source->getType() !== Type::Rest->value
			|| !self::checkPermissionAppBySourceId($params['appId'], $sourceId)
		)
		{
			$result = new Result();
			$result->addError(new Error('Source was not found.', 'SOURCE_NOT_FOUND'));

			return $result;
		}

		$checker = new TableAccessChecker();
		if (!$checker->isRestricted())
		{
			$tableGroupIds = null;
		}
		elseif ($tableGroupIds === [])
		{
			return self::getTableGroupRequiredResult();
		}
		elseif ($tableGroupIds !== null)
		{
			if (!$checker->canUseEveryGroup(ActionDictionary::ACTION_BIC_TABLE_EDIT, $tableGroupIds))
			{
				return self::getAccessDeniedResult();
			}
		}
		else
		{
			$userTablesGroupId = TableGroupMembershipService::getUserTablesGroupId();
			if (
				$userTablesGroupId === null
				|| !$checker->canUseGroup(ActionDictionary::ACTION_BIC_TABLE_EDIT, $userTablesGroupId)
			)
			{
				return self::getTableGroupRequiredResult();
			}

			// Bound explicitly rather than by DynamicTableBindingHandler, which skips the reserved code check
			$tableGroupIds = [$userTablesGroupId];
		}

		if ($tableGroupIds !== null && !$checker->canTakeTableCode((string)$dataset['NAME']))
		{
			return self::getAccessDeniedResult();
		}

		if (self::$useOldBehavior)
		{
			$result = RestDatasetManager::addWithDataset(
				$dataset,
				$datasetFields,
				sourceId: $sourceId,
				tableGroupIds: $tableGroupIds,
			);
		}
		else
		{
			$result = RestDatasetManager::add(
				$dataset,
				$datasetFields,
				sourceId: $sourceId,
				tableGroupIds: $tableGroupIds,
			);
		}

		return self::convertTableGroupErrors($result);
	}

	protected static function updateEntity(array $params): Result
	{
		$datasetId = (int)$params['id'];

		$dataset = RestDatasetManager::getById($datasetId);
		if (
			!$dataset
			|| $dataset->getType() !== Type::Rest->value
			|| !self::checkPermissionAppByDatasetId($params['appId'], $datasetId)
		)
		{
			$result = new Result();
			$result->addError(new Error('Dataset was not found.', 'DATASET_NOT_FOUND'));

			return $result;
		}

		if (isset($params['fields']['fields']))
		{
			$result = new Result();
			$result->addError(new Error('Use the method "biconnector.dataset.fields.update" to update the dataset fields."', 'INVALID_METHOD'));

			return $result;
		}

		$converter = new Converter(
			Converter::KEYS
			| Converter::RECURSIVE
			| Converter::TO_SNAKE
			| Converter::TO_UPPER
		);
		$datasetInfo = $converter->process($params['fields']) ?? [];
		$extractResult = self::extractTableGroupIds($datasetInfo);
		if (!$extractResult->isSuccess())
		{
			return $extractResult;
		}
		$tableGroupIds = $extractResult->getData()['tableGroupIds'];

		$checker = new TableAccessChecker();
		if (!$checker->isRestricted())
		{
			$tableGroupIds = null;
		}
		else
		{
			$tableCode = (string)$dataset->getName();
			if (!$checker->canDo(ActionDictionary::ACTION_BIC_TABLE_EDIT, $tableCode))
			{
				return self::getAccessDeniedResult();
			}

			if ($tableGroupIds === [])
			{
				return self::getTableGroupRequiredResult();
			}

			if ($tableGroupIds !== null && !$checker->canChangeTableGroups($tableCode, $tableGroupIds))
			{
				return self::getAccessDeniedResult();
			}
		}

		$result = RestDatasetManager::update(
			$datasetId,
			$datasetInfo,
			tableGroupIds: $tableGroupIds,
		);

		return self::convertTableGroupErrors($result);
	}

	protected static function deleteEntity(array $params): Result
	{
		$id = (int)$params['id'];
		$dataset = RestDatasetManager::getById($id);
		if (
			!$dataset
			|| $dataset->getType() !== Type::Rest->value
			|| !self::checkPermissionAppByDatasetId($params['appId'], $id)
		)
		{
			$result = new Result();
			$result->addError(new Error('Dataset was not found.', 'DATASET_NOT_FOUND'));

			return $result;
		}

		if (!self::canEditTable((string)$dataset->getName()))
		{
			return self::getAccessDeniedResult();
		}

		if (self::$useOldBehavior)
		{
			return RestDatasetManager::deleteWithDataset($id);
		}

		return RestDatasetManager::delete($id);
	}

	protected static function getEntity(array $params): Result
	{
		$result = new Result();
		$id = (int)$params['id'];
		$dataset = RestDatasetManager::getById($id);

		if (
			!$dataset
			|| $dataset->getType() !== Type::Rest->value
			|| !self::checkPermissionAppByDatasetId($params['appId'], $id)
		)
		{
			$result->addError(new Error('Dataset was not found.', 'DATASET_NOT_FOUND'));

			return $result;
		}

		$datasetArray = $dataset->toArray();
		$datasetArray['fields'] = array_values((RestDatasetManager::getDatasetFieldsById($id)->collectValues()));
		$datasetArray = array_map('self::formatDateTime', $datasetArray);

		$datasetArray = Converter::toJson()->process($datasetArray);
		$result->setData($datasetArray);

		return $result;
	}

	protected static function getList(array $params): Result
	{
		$result = new Result();

		$select = $params['select'];
		$filter = $params['filter'];
		$order = $params['order'];
		$offset = $params['offset'];
		$limit = self::ORM_LIMIT;

		$rawDataCollection = ExternalSourceDatasetRelationTable::getList([
			'select' => $select,
			'filter' => $filter,
			'order' => $order,
			'offset' => $offset,
			'limit' => $limit,
			'runtime' => [
				(new ReferenceField(
					'SOURCE_CONNECTOR',
					ExternalSourceRestTable::class,
					Join::on('this.SOURCE_ID', 'ref.SOURCE_ID')
				))->configureJoinType(Join::TYPE_LEFT),
			],
		])->fetchCollection();

		$resultArray = [];
		foreach ($rawDataCollection as $rawData)
		{
			$datasetColumns = $rawData->getDataset()->collectValues();
			if (in_array('SOURCE_ID', $select, true))
			{
				$datasetColumns['SOURCE_ID'] = $rawData->getSourceId();
			}
			$resultArray[] = $datasetColumns;
		}

		if (
			in_array('DATASET', $select, true)
			|| in_array('DATE_CREATE', $select, true)
			|| in_array('DATE_UPDATE', $select, true)
		)
		{
			$resultArray = array_map(static function($value) {
				return array_map('self::formatDateTime', $value);
			}, $resultArray);
		}

		$resultArray = Converter::toJson()->process($resultArray);
		$result->setData($resultArray);

		return $result;
	}

	protected static function prepareSelect(array $select): array
	{
		if ($select === ['*'])
		{
			return ['DATASET', 'SOURCE_ID'];
		}
		$resultSelect = [];

		foreach ($select as $item)
		{
			$newItem = match ($item){
				'SOURCE_ID' => 'SOURCE_ID',
				'FIELDS' => null,
				default => 'DATASET.' . $item,
			};
			$resultSelect[] = $newItem;
		}
		$resultSelect = array_filter($resultSelect);

		return $resultSelect;
	}

	protected static function prepareFilter(array $filters, string $appId): array
	{
		$resultFilter = [
			'DATASET.TYPE' => Type::Rest->value,
			'SOURCE_CONNECTOR.CONNECTOR.APP_ID' => $appId,
		];
		$pattern = '/^([' . self::ALLOWED_PREFIX_ORM_FILTER . ']*)([A-Z]+)/';

		if ($filters === [])
		{
			return $resultFilter;
		}

		foreach ($filters as $filter => $value)
		{
			$truncatedFilter = ltrim($filter, self::ALLOWED_PREFIX_ORM_FILTER);
			$newFilter = match ($truncatedFilter) {
				'SOURCE_ID' => $filter,
				'FIELDS' => null,
				default => preg_replace($pattern, '$1DATASET.$2', $filter),
			};

			if (is_null($newFilter) || $value === [])
			{
				continue;
			}
			$resultFilter[$newFilter] = $value;
		}

		return $resultFilter;
	}

	protected static function prepareOrder(array $order): array
	{
		$resultOrder = [];
		if ($order === [])
		{
			return [];
		}

		foreach ($order as $field => $value)
		{
			$newField = match ($field) {
				'SOURCE_ID' => $field,
				'FIELDS' => null,
				default => 'DATASET.' . $field,
			};
			if (is_null($newField))
			{
				continue;
			}
			$resultOrder[$newField] = $value;
		}

		return $resultOrder;
	}

	private static function checkDatasetName(?string $name = null): bool
	{
		return preg_match('/^[a-z][a-z0-9_]*$/', $name ?? '');
	}

	/**
	 * Takes the groups of the table out of the converted fields: they are not a column of the dataset.
	 *
	 * @param array $fields Fields converted to the keys of the dataset.
	 *
	 * @return Result Data key tableGroupIds: null without the field, otherwise the ids without repeats.
	 */
	private static function extractTableGroupIds(array &$fields): Result
	{
		$result = new Result();
		if (!array_key_exists('TABLE_GROUP_IDS', $fields))
		{
			return $result->setData(['tableGroupIds' => null]);
		}

		$rawGroupIds = $fields['TABLE_GROUP_IDS'];
		unset($fields['TABLE_GROUP_IDS']);

		$tableGroupIds = [];
		foreach ((array)$rawGroupIds as $rawGroupId)
		{
			$groupId = filter_var($rawGroupId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($groupId === false || is_bool($rawGroupId))
			{
				return $result->addError(new Error(
					'Field "tableGroupIds" must be an array of positive integers.',
					'VALIDATION_INVALID_FIELD_TYPE'
				));
			}

			$tableGroupIds[$groupId] = $groupId;
		}

		return $result->setData(['tableGroupIds' => array_values($tableGroupIds)]);
	}

	private static function getTableGroupRequiredResult(): Result
	{
		return (new Result())->addError(new Error(
			'Table group is not specified.',
			'VALIDATION_TABLE_GROUP_REQUIRED'
		));
	}

	private static function canEditTable(string $tableCode): bool
	{
		$checker = new TableAccessChecker();

		return !$checker->isRestricted() || $checker->canDo(ActionDictionary::ACTION_BIC_TABLE_EDIT, $tableCode);
	}

	private static function getAccessDeniedResult(): Result
	{
		return (new Result())->addError(new Error(
			self::ACCESS_ERROR['error_description'],
			self::ACCESS_ERROR['error']
		));
	}

	private static function convertTableGroupErrors(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}

		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === TableGroupService::ERROR_TABLE_CODE_RESERVED)
			{
				return (new Result())->addError(new Error(
					'Dataset name is reserved by a system table.',
					'VALIDATION_DATASET_NAME_RESERVED'
				));
			}
		}

		return $result;
	}

	public static function fieldsUpdate(array $params, $n, \CRestServer $server): array|bool
	{
		\Bitrix\Main\Localization\Loc::setCurrentLang('en');
		if (!self::checkPermission())
		{
			return ['error' => self::ACCESS_ERROR];
		}

		$checkAppIdResult = self::checkAndPrepareAppId($server);
		if (!$checkAppIdResult->isSuccess())
		{
			return ['error' => self::ACCESS_ERROR];
		}
		$params['appId'] = $checkAppIdResult->getData()['appId'];
		$validateResult = static::validateParamsForFieldsUpdate($params);

		if (!$validateResult->isSuccess())
		{
			$error = $validateResult->getErrors()[0];

			return ['error' => [
				'error' => $error->getCode(),
				'error_description' => $error->getMessage(),
			]];
		}

		$dataset = RestDatasetManager::getById($validateResult->getData()['datasetId']);
		if (
			!$dataset
			|| $dataset->getType() !== Type::Rest->value
			|| !self::checkPermissionAppByDatasetId($params['appId'], $dataset->getId())
		)
		{
			$error = new Error('Dataset was not found.', 'DATASET_NOT_FOUND');

			return ['error' => [
				'error' => $error->getCode(),
				'error_description' => $error->getMessage(),
			]];
		}

		if (!self::canEditTable((string)$dataset->getName()))
		{
			return ['error' => self::ACCESS_ERROR];
		}

		$result = RestDatasetManager::updateFieldsByDatasetId(
			$dataset,
			$validateResult->getData()['fieldsToAdd'],
			$validateResult->getData()['fieldsToUpdate'],
			$validateResult->getData()['fieldsToDelete'],
		);
		if (!$result->isSuccess())
		{
			$error = $result->getErrors()[0];

			return ['error' => [
				'error' => $error->getCode(),
				'error_description' => $error->getMessage(),
			]];
		}

		return true;
	}

	private static function validateParamsForFieldsUpdate(array $params): Result
	{
		$result = self::validateParamsId($params);
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (isset($params['add']) && is_array($params['add']))
		{
			$addArray = [];
			foreach ($params['add'] as $field)
			{
				if (!isset($field['name'], $field['externalCode'], $field['type']))
				{
					$result->addError(new Error('Field to be added must include the required parameters: "name", "externalCode" and "type".', 'VALIDATION_FIELD_ADD_MISSING_REQUIRED_FIELDS'));

					return $result;
				}
				$addArray[] = [
					'NAME' => $field['name'],
					'EXTERNAL_CODE' => $field['externalCode'],
					'TYPE' => $field['type'],
				];
			}
		}

		if (isset($params['update']) && is_array($params['update']))
		{
			$updateArray = [];
			foreach ($params['update'] as $field)
			{
				if (!isset($field['id'], $field['visible']))
				{
					$result->addError(new Error('Field to be updated must include the required parameters: "id" and "visible".', 'VALIDATION_FIELD_UPDATE_MISSING_REQUIRED_FIELDS'));

					return $result;
				}
				$updateArray[] = [
					'ID' => $field['id'],
					'VISIBLE' => $field['visible'],
				];
			}
		}

		if (isset($params['delete']) && is_array($params['delete']))
		{
			$deleteArray = [];
			foreach ($params['delete'] as $id)
			{
				if (!is_numeric($id) || (int)$id < 1)
				{
					$result->addError(new Error('ID to be deleted must be a positive integer.', 'VALIDATION_FIELD_DELETE_INVALID_ID'));

					return $result;
				}
				$deleteArray[] = $id;
			}
		}

		$result->setData([
			'datasetId' => $params['id'],
			'fieldsToAdd' => $addArray ?? [],
			'fieldsToUpdate' => $updateArray ?? [],
			'fieldsToDelete' => $deleteArray ?? [],
		]);

		return $result;
	}

	public static function addOldBehavior(array $params, $n, \CRestServer $server): array|bool
	{
		self::$useOldBehavior = true;

		return self::add($params, $n, $server);
	}

	public static function deleteOldBehavior(array $params, $n, \CRestServer $server): array|bool
	{
		self::$useOldBehavior = true;

		return self::delete($params, $n, $server);
	}
}
