<?php

namespace Bitrix\BIConnector\ExternalSource;

use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Access\Table\PermissionCacheResetEventHandler;
use Bitrix\BIConnector\Superset\Cache\TableListCacheInvalidator;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Converter;

class DatasetManager
{
	public const EVENT_ON_BEFORE_ADD_DATASET = 'onBeforeAddDataset';
	public const EVENT_ON_AFTER_ADD_DATASET = 'onAfterAddDataset';
	public const EVENT_ON_BEFORE_UPDATE_DATASET = 'onBeforeUpdateDataset';
	public const EVENT_ON_AFTER_UPDATE_DATASET = 'onAfterUpdateDataset';
	public const EVENT_ON_AFTER_DELETE_DATASET = 'onAfterDeleteDataset';

	/**
	 * Adds new dataset with field and settings
	 *
	 * @param array $dataset
	 * @param array $fields
	 * @param array $settings
	 * @param int|null $sourceId
	 * @param int[]|null $tableGroupIds Groups the table is bound to, exactly these; null leaves the binding
	 *                                  to DynamicTableBindingHandler.
	 *
	 * @return Main\Result
	 */
	public static function add(
		array $dataset,
		array $fields,
		array $settings = [],
		?int $sourceId = null,
		?array $tableGroupIds = null,
	): Main\Result
	{
		$result = new Main\Result();

		$event = new Main\Event(
			'biconnector',
			self::EVENT_ON_BEFORE_ADD_DATASET,
			[
				'dataset' => $dataset,
				'fields' => $fields,
				'settings' => $settings,
			],
		);
		$event->send();

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === Main\EventResult::ERROR)
			{
				$error = $eventResult->getParameters();
				$result->addError(
					$error instanceof Main\Error
						? $error
						: new Main\Error(Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_ADD_ERROR_MSGVER_1') ?? ''),
				);

				return $result;
			}
		}

		$checkResult = static::checkAndPrepareBeforeAdd($dataset, $fields, $settings, $sourceId);
		if (!$checkResult->isSuccess())
		{
			$result->addErrors($checkResult->getErrors());

			return $result;
		}

		$checkResultData = $checkResult->getData();

		$dataset = $checkResultData['dataset'];
		$fields = $checkResultData['fields'];
		$settings = $checkResultData['settings'];

		$connection = Main\Application::getInstance()->getConnection();
		$connection->startTransaction();

		$id = null;

		// The bindings go before the row of the dataset: DynamicTableBindingHandler skips a code bound
		// already, so the table ends up in the chosen groups only.
		if ($tableGroupIds !== null)
		{
			$bindingResult = self::setTableGroups((string)$dataset['NAME'], $tableGroupIds);
			if (!$bindingResult->isSuccess())
			{
				self::rollback($connection);

				return $result->addErrors($bindingResult->getErrors());
			}
		}

		try
		{
			$datasetAddResult = Internal\ExternalDatasetTable::add($dataset);
		}
		catch (Main\DB\DuplicateEntryException)
		{
			self::rollback($connection);

			$result->addError(
				new Main\Error(
					Loc::getMessage(
						'BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_NAME_EXISTS_ERROR',
						['#NAME#' => $dataset['NAME']],
					) ?? '',
					'NAME_EXISTS',
				),
			);

			return $result;
		}

		if ($datasetAddResult->isSuccess())
		{
			$id = $datasetAddResult->getId();

			$addFieldsResult = self::addFieldsToDataset($id, $fields);
			if (!$addFieldsResult->isSuccess())
			{
				$result->addErrors($addFieldsResult->getErrors());
			}

			if ($settings)
			{
				$addSettingsResult = self::addSettingsToDataset($id, $settings);
				if (!$addSettingsResult->isSuccess())
				{
					$result->addErrors($addSettingsResult->getErrors());
				}
			}

			if ($sourceId)
			{
				$relationAddResult = Internal\ExternalSourceDatasetRelationTable::addRelation($sourceId, $id);
				if (!$relationAddResult->isSuccess())
				{
					$result->addErrors($relationAddResult->getErrors());
				}
			}
		}
		else
		{
			$result->addErrors($datasetAddResult->getErrors());
		}

		if ($result->isSuccess())
		{
			$connection->commitTransaction();
			$result->setData([
				'id' => $id,
				'dataset' => $datasetAddResult->getData(),
			]);

			if ($tableGroupIds !== null)
			{
				TableGroupService::resetGroupCaches();
			}

			TableListCacheInvalidator::schedule();
		}
		else
		{
			self::rollback($connection);
		}

		return $result;
	}

	protected static function checkAndPrepareBeforeAdd(
		array $dataset,
		array $fields,
		array $settings,
		?int $sourceId = null,
	): Main\Result
	{
		$result = new Main\Result();

		$checkBeforeResult = self::checkBefore($dataset, $fields, $settings);
		if (!$checkBeforeResult->isSuccess())
		{
			$result->addErrors($checkBeforeResult->getErrors());

			return $result;
		}

		if (empty($dataset))
		{
			$result->addError(new Main\Error('$dataset is empty', 'DATASET_EMPTY'));
		}
		else
		{
			if (
				in_array($dataset['TYPE'] ?? null, [Type::Mysql->value, Type::Pgsql->value], true)
				&& !self::isExternalCodeValid($dataset['EXTERNAL_CODE'] ?? null)
			)
			{
				return $result->addError(
					new Main\Error(
						Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_EXTERNAL_CODE_FORMAT_ERROR') ?? '',
						'EXTERNAL_CODE_FORMAT',
					),
				);
			}

			$datasetName = $dataset['NAME'];
			if (!preg_match(Internal\ExternalDatasetTable::TABLE_NAME_REGEXP, (string)$datasetName))
			{
				$result->addError(
					new Main\Error(
						Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_NAME_FORMAT_ERROR') ?? '',
						'NAME_FORMAT',
					),
				);
			}
			elseif (in_array($datasetName, SupersetServiceIntegration::getTableList(), true))
			{
				$result->addError(
					new Main\Error(
						Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_DATASET_ALREADY_EXIST_ERROR_MSGVER_1') ?? '',
						'DATASET_ALREADY_EXIST',
					),
				);
			}
			elseif (self::isNameTaken($datasetName))
			{
				$result->addError(
					new Main\Error(
						Loc::getMessage(
							'BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_NAME_EXISTS_ERROR',
							['#NAME#' => $datasetName],
						) ?? '',
						'NAME_EXISTS',
					),
				);
			}

			// The database assigns the ID: PostgreSQL stores an explicit zero from the form as is.
			unset($dataset['ID']);
			$dataset['DATE_CREATE'] = new Main\Type\DateTime();

			if (empty($dataset['CREATED_BY_ID']) && CurrentUser::get()->getId())
			{
				$dataset['CREATED_BY_ID'] = CurrentUser::get()->getId();
			}
		}

		if (empty($fields))
		{
			$result->addError(new Main\Error('$fields is empty', 'FIELDS_EMPTY'));
		}

		if (empty($settings) && is_null($sourceId))
		{
			$result->addError(new Main\Error('$settings is empty', 'SETTINGS_EMPTY'));
		}

		$result->setData([
			'dataset' => $dataset,
			'fields' => $fields,
			'settings' => $settings,
		]);

		return $result;
	}

	/**
	 * Updates dataset with field and settings
	 *
	 * @param int $id
	 * @param array $dataset
	 * @param array $fields
	 * @param array $settings
	 * @param int[]|null $tableGroupIds Groups the table ends up in, exactly these; null leaves the bindings.
	 * @return Main\Result
	 */
	public static function update(
		int $id,
		array $dataset = [],
		array $fields = [],
		array $settings = [],
		?array $tableGroupIds = null,
	): Main\Result
	{
		$result = new Main\Result();

		$event = new Main\Event(
			'biconnector',
			self::EVENT_ON_BEFORE_UPDATE_DATASET,
			[
				'id' => $id,
				'dataset' => $dataset,
				'fields' => $fields,
				'settings' => $settings,
			],
		);
		$event->send();

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === Main\EventResult::ERROR)
			{
				$error = $eventResult->getParameters();
				$result->addError(
					$error instanceof Main\Error
						? $error
						: new Main\Error(Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_UPDATE_ERROR_MSGVER_1') ?? ''),
				);

				return $result;
			}
		}

		$checkResult = static::checkAndPrepareBeforeUpdate($id, $dataset, $fields, $settings);
		if (!$checkResult->isSuccess())
		{
			$result->addErrors($checkResult->getErrors());

			return $result;
		}

		$checkResultData = $checkResult->getData();

		$dataset = $checkResultData['dataset'];
		$fields = $checkResultData['fields'];
		$settings = $checkResultData['settings'];
		$tableCode = $checkResultData['name'];

		$connection = Main\Application::getInstance()->getConnection();
		$connection->startTransaction();

		if ($dataset)
		{
			$datasetUpdateResult = Internal\ExternalDatasetTable::update($id, $dataset);
			if (!$datasetUpdateResult->isSuccess())
			{
				$result->addErrors($datasetUpdateResult->getErrors());
			}
		}

		if ($fields)
		{
			$currentFields = self::getDatasetFieldsById($id);

			if (isset($fields[0]['id'], $fields[0]['visible']))
			{
				$converter = new Converter(Converter::KEYS | Converter::TO_UPPER | Converter::RECURSIVE);
				$fields = $converter->process($fields);
			}

			$fieldsToUpdate = array_filter($fields, static function ($field) use ($currentFields) {
				return isset($field['ID']) && $currentFields->getByPrimary($field['ID']) !== null;
			});

			foreach ($fieldsToUpdate as $fieldToUpdate)
			{
				$currentField = $currentFields->getByPrimary($fieldToUpdate['ID']);
				if (isset($fieldToUpdate['VISIBLE']) && $fieldToUpdate['VISIBLE'] !== $currentField->getVisible())
				{
					$currentField->setVisible($fieldToUpdate['VISIBLE']);
				}

				if (isset($fieldToUpdate['NAME']) && $fieldToUpdate['NAME'] !== $currentField->getName())
				{
					$currentField->setName($fieldToUpdate['NAME']);
				}

				if (
					array_key_exists('DESCRIPTION', $fieldToUpdate)
					&& (string)$fieldToUpdate['DESCRIPTION'] !== (string)$currentField->getDescription()
				)
				{
					$currentField->setDescription($fieldToUpdate['DESCRIPTION']);
				}

				if (
					$currentField->isVisibleChanged()
					|| $currentField->isNameChanged()
					|| $currentField->isDescriptionChanged()
				)
				{
					$saveFieldResult = $currentField->save();
					if (!$saveFieldResult->isSuccess())
					{
						$result->addErrors($saveFieldResult->getErrors());
					}
				}
			}

			$fieldsToAdd = array_filter($fields, static function ($field) {
				return !isset($field['ID']) || (int)$field['ID'] === 0;
			});
			if ($fieldsToAdd)
			{
				$addFieldsResult = self::addFieldsToDataset($id, $fieldsToAdd);
				if (!$addFieldsResult->isSuccess())
				{
					$result->addErrors($addFieldsResult->getErrors());
				}
			}

			$fieldsToDelete = array_diff(
				$currentFields->getIdList(),
				array_map('intval', array_column($fields, 'ID')),
			);
			if ($fieldsToDelete)
			{
				Internal\ExternalDatasetFieldTable::deleteByFilter(['=ID' => $fieldsToDelete]);
			}
		}

		if ($settings)
		{
			$deleteDatasetSettingsResult = Internal\ExternalDatasetFieldFormatTable::deleteByDatasetId($id);
			if (!$deleteDatasetSettingsResult->isSuccess())
			{
				$result->addErrors($deleteDatasetSettingsResult->getErrors());
			}

			$addSettingsResult = self::addSettingsToDataset($id, $settings);
			if (!$addSettingsResult->isSuccess())
			{
				$result->addErrors($addSettingsResult->getErrors());
			}
		}

		$isTableGroupSetChanged = false;
		if ($tableGroupIds !== null && $result->isSuccess())
		{
			$setTableGroupsResult = self::setTableGroups($tableCode, $tableGroupIds);
			$result->addErrors($setTableGroupsResult->getErrors());
			$changes = $setTableGroupsResult->getData();
			$isTableGroupSetChanged = !empty($changes['addedGroupIds']) || !empty($changes['removedGroupIds']);
		}

		if ($result->isSuccess())
		{
			$connection->commitTransaction();
		}
		else
		{
			self::rollback($connection);

			return $result;
		}

		if ($isTableGroupSetChanged)
		{
			TableGroupService::resetGroupCaches();
		}

		$event = new Main\Event(
			'biconnector',
			self::EVENT_ON_AFTER_UPDATE_DATASET,
			[
				'dataset' => self::getById($id),
			],
		);
		$event->send();

		TableListCacheInvalidator::schedule();

		return $result;
	}

	protected static function checkAndPrepareBeforeUpdate(int $id, array $dataset, array $fields, array $settings): Main\Result
	{
		$result = new Main\Result();

		$checkBeforeResult = self::checkBefore($dataset, $fields, $settings);
		if (!$checkBeforeResult->isSuccess())
		{
			$result->addErrors($checkBeforeResult->getErrors());

			return $result;
		}

		$currentDataset = self::getById($id);
		if (!$currentDataset)
		{
			$result->addError(
				new Main\Error(
					Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_DATASET_NOT_FOUND_MSGVER_1') ?? '',
				),
			);

			return $result;
		}

		if (
			array_key_exists('EXTERNAL_CODE', $dataset)
			&& in_array($currentDataset->getType(), [Type::Mysql->value, Type::Pgsql->value], true)
			&& !self::isExternalCodeValid($dataset['EXTERNAL_CODE'])
		)
		{
			return $result->addError(
				new Main\Error(
					Loc::getMessage('BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_EXTERNAL_CODE_FORMAT_ERROR') ?? '',
					'EXTERNAL_CODE_FORMAT',
				),
			);
		}

		if ($dataset)
		{
			$dataset['DATE_UPDATE'] = new Main\Type\DateTime();

			if (empty($dataset['UPDATED_BY_ID']) && CurrentUser::get()->getId())
			{
				$dataset['UPDATED_BY_ID'] = CurrentUser::get()->getId();
			}

			unset($dataset['NAME'], $dataset['TYPE']);
		}

		$result->setData([
			'dataset' => $dataset,
			'fields' => $fields,
			'settings' => $settings,
			'name' => (string)$currentDataset->getName(),
		]);

		return $result;
	}

	protected static function checkBefore(array $dataset, array $fields, array $settings): Main\Result
	{
		$result = new Main\Result();

		if ($fields)
		{
			$fieldNames = array_column($fields, 'NAME');
			$duplicates = array_filter(array_count_values($fieldNames), static function($count) {
				return $count > 1;
			});
			if ($duplicates)
			{
				$result->addError(
					new Main\Error(
						Loc::getMessage(
							'BICONNECTOR_EXTERNAL_SOURCE_DATASET_MANAGER_DUPLICATE_FIELDS_ERROR',
							['#FIELD_NAMES#' => implode(', ', array_keys($duplicates))],
						),
						'DUPLICATE_FIELDS',
					),
				);
			}
		}

		return $result;
	}

	private static function isExternalCodeValid(mixed $externalCode): bool
	{
		return is_string($externalCode) && $externalCode !== '' && !str_contains($externalCode, "\0");
	}

	private static function isNameTaken(string $name): bool
	{
		$row = Internal\ExternalDatasetTable::getRow([
			'select' => ['ID'],
			'filter' => ['=NAME' => $name],
		]);

		return $row !== null;
	}

	protected static function addFieldsToDataset(int $id, array $fields): Main\Result
	{
		$result = new Main\Result();

		foreach ($fields as $field)
		{
			unset($field['ID']);
			$field['DATASET_ID'] = $id;
			$datasetFieldsAddResult = Internal\ExternalDatasetFieldTable::add($field);
			if (!$datasetFieldsAddResult->isSuccess())
			{
				$result->addErrors($datasetFieldsAddResult->getErrors());
			}
		}

		return $result;
	}

	private static function addSettingsToDataset(int $id, array $settings): Main\Result
	{
		$result = new Main\Result();

		foreach ($settings as $setting)
		{
			$setting['DATASET_ID'] = $id;
			$datasetSettingsAddResult = Internal\ExternalDatasetFieldFormatTable::add($setting);
			if (!$datasetSettingsAddResult->isSuccess())
			{
				$result->addErrors($datasetSettingsAddResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * Deletes dataset with fields and settings
	 *
	 * The bindings of its table to the table groups are dropped by DynamicTableBindingHandler on the
	 * deletion events of the row.
	 *
	 * @param int $id
	 * @return Main\Result
	 */
	public static function delete(int $id): Main\Result
	{
		$result = new Main\Result();

		$dataset = self::getById($id);
		if (!$dataset)
		{
			return $result;
		}

		$datasetDeleteResult = Internal\ExternalDatasetTable::delete($id);
		if (!$datasetDeleteResult->isSuccess())
		{
			$result->addErrors($datasetDeleteResult->getErrors());

			return $result;
		}

		// onAfterDeleteDataset is sent by RestDatasetManager::deleteWithDataset() only, while the catalog
		// changes on every path; a repeated call in the same hit costs nothing
		PermissionCacheResetEventHandler::onDatasetChanged();

		TableListCacheInvalidator::schedule();

		return $result;
	}

	private static function setTableGroups(string $tableCode, array $tableGroupIds): Main\Result
	{
		try
		{
			return TableGroupService::setTableGroupsWithinTransaction($tableCode, $tableGroupIds);
		}
		catch (\Throwable $exception)
		{
			return (new Main\Result())->addError(new Main\Error($exception->getMessage()));
		}
	}

	/**
	 * Inside an outer transaction the rollback returns to the savepoint and then reports the nesting as
	 * an exception; the changes are undone by then and the outer transaction decides the rest.
	 */
	private static function rollback(Main\DB\Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (Main\DB\TransactionException)
		{
		}
	}

	/**
	 * Gets dataset data
	 *
	 * @param int $id
	 * @return Internal\ExternalDataset|null
	 */
	public static function getById(int $id): ?Internal\ExternalDataset
	{
		return Internal\ExternalDatasetTable::getById($id)?->fetchObject();
	}

	/**
	 * Gets list of datasets
	 *
	 * @param array $filter
	 * @return Internal\ExternalDatasetCollection
	 */
	public static function getList(array $filter = []): Internal\ExternalDatasetCollection
	{
		$datasetResult = Internal\ExternalDatasetTable::getList([
			'select' => ['*'],
			'filter' => $filter,
		])->fetchCollection();

		return $datasetResult;
	}

	/**
	 * Gets fields by dataset id
	 *
	 * @param int $id
	 * @return Internal\ExternalDatasetFieldCollection
	 */
	public static function getDatasetFieldsById(int $id): Internal\ExternalDatasetFieldCollection
	{
		$datasetFieldsResult = Internal\ExternalDatasetFieldTable::getList([
			'select' => ['*'],
			'filter' => [
				'=DATASET_ID' => $id,
			],
		])->fetchCollection();

		return $datasetFieldsResult;
	}

	/**
	 * Stores the settings a CSV file was actually parsed with.
	 *
	 * Called after a successful import, not together with the dataset row: the import runs outside
	 * the dataset transaction and may fail, and settings must never describe data that was parsed
	 * with something else. Deliberately bypasses self::update() so that dataset lifecycle events —
	 * and the Superset mirroring hanging on them — are not fired a second time per save.
	 *
	 * @param int $datasetId
	 * @param array $settings Keys: delimiter, encoding, hasHeaders.
	 * @return Main\Result
	 */
	public static function saveFileParsingSettings(int $datasetId, array $settings): Main\Result
	{
		$result = new Main\Result();

		foreach (['delimiter', 'encoding', 'hasHeaders'] as $key)
		{
			if (!isset($settings[$key]))
			{
				$result->addError(new Main\Error('Setting "' . $key . '" must be set', 'FILE_PARSING_SETTINGS_INCOMPLETE'));

				return $result;
			}
		}

		try
		{
			$updateResult = Internal\ExternalDatasetTable::update($datasetId, [
				'CSV_DELIMITER' => $settings['delimiter'],
				'CSV_ENCODING' => $settings['encoding'],
				'CSV_HAS_HEADERS' => (bool)$settings['hasHeaders'],
			]);
		}
		catch (\Throwable $exception)
		{
			// The caller runs this after a finished import and only logs the failure, so a storage
			// exception must not escape and turn an imported dataset into a failed request.
			$result->addError(new Main\Error($exception->getMessage(), 'FILE_PARSING_SETTINGS_SAVE_ERROR'));

			return $result;
		}

		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());
		}

		return $result;
	}

	/**
	 * Gets settings by dataset id
	 *
	 * @param int $id
	 * @return Internal\ExternalDatasetFieldFormatCollection
	 */
	public static function getDatasetSettingsById(int $id): Internal\ExternalDatasetFieldFormatCollection
	{
		$datasetSettingsResult = Internal\ExternalDatasetFieldFormatTable::getList([
			'select' => ['*'],
			'filter' => [
				'=DATASET_ID' => $id,
			],
		])->fetchCollection();

		return $datasetSettingsResult;
	}

	/**
	 * Gets  map fields by dataset name, ['code' => 'name']
	 *
	 * @param string $tableName
	 * @return array
	 */
	public static function getMapFieldsByTableName(string $tableName): array
	{
		$datasetFieldsResult = Internal\ExternalDatasetFieldTable::getList([
			'select' => ['EXTERNAL_CODE', 'NAME'],
			'filter' => [
				'=DATASET.EXTERNAL_CODE' => $tableName,
			],
		])
			->fetchCollection()
		;

		$mapFields = [];
		foreach ($datasetFieldsResult as $field)
		{
			$mapFields[$field->getExternalCode()] = $field->getName();
		}

		return $mapFields;
	}
}
