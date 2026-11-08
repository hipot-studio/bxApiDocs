<?php

namespace Bitrix\Main\UserField\Internal;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UserField\Types\BaseType;
use Bitrix\Main\UserField\Types\EnumType;
use Bitrix\Main\UserField\Types\FileType;

final class UserFieldHelper
{
	public const ERROR_CODE_USER_FIELD_CREATION = 'ERROR_CODE_USER_FIELD_CREATION';

	/** @var UserFieldHelper */
	private static $instance;

	private function __construct()
	{
	}

	private function __clone()
	{
	}

	/**
	 * Returns Singleton of Driver
	 * @return UserFieldHelper
	 */
	public static function getInstance(): UserFieldHelper
	{
		if (!isset(self::$instance))
		{
			self::$instance = new UserFieldHelper;
		}

		return self::$instance;
	}

	/**
	 * @return \CUserTypeManager
	 */
	public function getManager(): ?\CUserTypeManager
	{
		global $USER_FIELD_MANAGER;

		return $USER_FIELD_MANAGER;
	}

	/**
	 * @return \CMain
	 */
	public function getApplication(): ?\CMain
	{
		global $APPLICATION;

		return $APPLICATION;
	}

	/**
	 * @param string $entityId
	 * @return array|null
	 */
	public function parseUserFieldEntityId(string $entityId): ?array
	{
		if(preg_match('/^([A-Z]+)_([0-9A-Z_]+)$/', $entityId, $matches))
		{
			$typeCode = TypeFactory::getCodeByPrefix($matches[1]);
			$factory = Registry::getInstance()->getFactoryByCode($typeCode);
			if($factory)
			{
				$typeId = $factory->prepareIdentifier($matches[2]);
				if ($typeId > 0)
				{
					return [$factory, $typeId];
				}
			}
		}

		return null;
	}

	/**
	 * @param $field
	 * @return array|bool
	 */
	public static function OnBeforeUserTypeAdd($field)
	{
		if (static::getInstance()->parseUserFieldEntityId($field['ENTITY_ID']))
		{
			if (str_ends_with($field['FIELD_NAME'], '_REF'))
			{
				/**
				 * postfix _REF reserved for references to other highloadblocks
				 * @see CUserTypeHlblock::getEntityReferences
				 */
				global $APPLICATION;

				Loc::loadLanguageFile(__DIR__.'/highloadblock.php');
				$APPLICATION->ThrowException(
					Loc::getMessage('HIGHLOADBLOCK_HIGHLOAD_BLOCK_ENTITY_FIELD_NAME_REF_RESERVED'),
				);

				return false;
			}

			return [
				'PROVIDE_STORAGE' => false,
			];
		}

		return true;
	}

	/**
	 * @param $field
	 * @return array|bool
	 */
	public static function onAfterUserTypeAdd($field)
	{
		$userFieldHelper = static::getInstance();
		$parseResult = $userFieldHelper->parseUserFieldEntityId($field['ENTITY_ID']);
		if($parseResult)
		{
			[$factory, $typeId] = $parseResult;
			$userFieldManager = $userFieldHelper->getManager();
			$application = $userFieldHelper->getApplication();
			/** @var TypeFactory $factory */
			/** @var TypeDataManager $dataClass */
			$dataClass = $factory->getTypeDataClass();

			$field['USER_TYPE'] = $userFieldManager->getUserType($field['USER_TYPE_ID']);

			$typeData = $dataClass::getById($typeId)->fetch();

			if (empty($typeData))
			{
				$application->throwException(sprintf(
					'Entity "%s" wasn\'t found.', $factory->getUserFieldEntityId($typeId),
				));

				return false;
			}

			// get usertype info
			$sql_column_type = $userFieldManager->getUtsDBColumnType($field);

			// create field in db
			$connection = Application::getConnection();
			$sqlHelper = $connection->getSqlHelper();

			try
			{
				self::addUserFieldColumn($connection, $typeData['TABLE_NAME'], $field['FIELD_NAME'], $sql_column_type);

				if ($field['MULTIPLE'] == 'Y')
				{
					// create table for this relation
					$typeEntity = $dataClass::compileEntity($typeData);
					$utmEntity = Entity::getInstance($dataClass::getUtmEntityClassName($typeEntity, $field));

					$utmEntity->createDbTable();

					// add indexes
					$connection->query(sprintf(
						'CREATE INDEX %s ON %s (%s)',
						$sqlHelper->quote('IX_UTM_HL'.$typeId.'_'.$field['ID'].'_ID'),
						$sqlHelper->quote($utmEntity->getDBTableName()),
						$sqlHelper->quote('ID'),
					));

					$connection->query(sprintf(
						'CREATE INDEX %s ON %s (%s)',
						$sqlHelper->quote('IX_UTM_HL'.$typeId.'_'.$field['ID'].'_VALUE'),
						$sqlHelper->quote($utmEntity->getDBTableName()),
						$sqlHelper->quote('VALUE'),
					));
				}
			}
			catch (SqlQueryException $sqlQueryException)
			{
				$userTypeEntity = new \CUserTypeEntity;
				$userTypeEntity->Delete($field['ID']);

				throw new InvalidOperationException(
					'Could not create new user field ' . $field['FIELD_NAME'],
					$sqlQueryException,
				);
			}

			return [
				'PROVIDE_STORAGE' => false,
			];
		}

		return true;
	}

	private static function addUserFieldColumn(
		Connection $connection,
		string $tableName,
		string $fieldName,
		string $columnType,
	): void
	{
		$sqlHelper = $connection->getSqlHelper();
		$connection->query(sprintf(
			'ALTER TABLE %s ADD %s %s',
			$sqlHelper->quote($tableName),
			$sqlHelper->quote($fieldName),
			$columnType,
		));
		$connection->clearCaches($tableName);
	}

	/**
	 * @param $field
	 * @return array|bool
	 */
	public static function OnBeforeUserTypeDelete($field)
	{
		$userFieldHelper = static::getInstance();
		$parseResult = $userFieldHelper->parseUserFieldEntityId($field['ENTITY_ID']);
		if (!$parseResult)
		{
			return true;
		}

		[$factory, $typeId] = $parseResult;
		$dataClass = $factory->getTypeDataClass();
		$typeData = $dataClass::getById($typeId)->fetch();
		$field['USER_TYPE'] = $userFieldHelper->getManager()?->GetUserType($field['USER_TYPE_ID']);

		if ($typeData)
		{
			$cleanupResult = self::cleanupUserFieldValuesBeforeDelete(
				$field,
				self::getUserFieldValues($dataClass, $typeData, $field),
			);
			if (!$cleanupResult->isSuccess())
			{
				$userFieldHelper->getApplication()?->ThrowException(
					$cleanupResult->getError()?->getMessage() ?? 'Could not delete user field values.',
				);

				return false;
			}
		}

		return [
			'PROVIDE_STORAGE' => false,
		];
	}

	public static function cleanupUserFieldValuesBeforeDelete(
		array $field,
		iterable $values,
	): Result
	{
		$className = $field['USER_TYPE']['CLASS_NAME'] ?? null;
		if (!self::hasValuesDeleteHandler($field))
		{
			return new Result();
		}

		try
		{
			if (self::hasOwnTypeMethod($field, 'onBeforeDeleteValues'))
			{
				return $className::onBeforeDeleteValues($field, $values);
			}

			return FileType::onBeforeDeleteValues($field, $values);
		}
		catch (\Throwable $exception)
		{
			return (new Result())->addError(
				new Error($exception->getMessage(), 'USER_FIELD_DELETE_FAILED'),
			);
		}
	}

	public static function hasValuesDeleteHandler(array $field): bool
	{
		return self::hasOwnTypeMethod($field, 'onBeforeDeleteValues')
			|| ($field['USER_TYPE']['BASE_TYPE'] ?? null) === \CUserTypeManager::BASE_TYPE_FILE;
	}

	public static function cleanupUserFieldDefinitionBeforeDelete(array $field): Result
	{
		$className = $field['USER_TYPE']['CLASS_NAME'] ?? null;
		if (!self::hasFieldDeleteHandler($field))
		{
			return new Result();
		}

		try
		{
			if (self::hasOwnTypeMethod($field, 'onBeforeDeleteField'))
			{
				return $className::onBeforeDeleteField($field);
			}

			return EnumType::onBeforeDeleteField($field);
		}
		catch (\Throwable $exception)
		{
			return (new Result())->addError(
				new Error($exception->getMessage(), 'USER_FIELD_DELETE_FAILED'),
			);
		}
	}

	public static function hasFieldDeleteHandler(array $field): bool
	{
		return self::hasOwnTypeMethod($field, 'onBeforeDeleteField')
			|| ($field['USER_TYPE']['BASE_TYPE'] ?? null) === \CUserTypeManager::BASE_TYPE_ENUM;
	}

	public static function hasDeleteLifecycle(array $field): bool
	{
		return self::hasValuesDeleteHandler($field) || self::hasFieldDeleteHandler($field);
	}

	private static function hasOwnTypeMethod(array $field, string $method): bool
	{
		$className = $field['USER_TYPE']['CLASS_NAME'] ?? null;
		if (!is_string($className) || !is_a($className, BaseType::class, true))
		{
			return false;
		}

		return (new \ReflectionMethod($className, $method))->getDeclaringClass()->getName()
			!== BaseType::class;
	}

	private static function getUserFieldValues(string $dataClass, array $typeData, array $field): iterable
	{
		$connection = Application::getConnection();
		if ($field['MULTIPLE'] === 'Y')
		{
			$tableName = $dataClass::getMultipleValueTableName($typeData, $field);
			if (!$connection->isTableExists($tableName))
			{
				return;
			}

			$query = new SqlExpression('SELECT ?# AS ?# FROM ?#', 'VALUE', 'VALUE', $tableName);
		}
		else
		{
			if (!$connection->getTableField($typeData['TABLE_NAME'], $field['FIELD_NAME']))
			{
				return;
			}

			$query = new SqlExpression(
				'SELECT ?# AS ?# FROM ?#',
				$field['FIELD_NAME'],
				'VALUE',
				$typeData['TABLE_NAME'],
			);
		}

		$queryResult = $connection->query($query->compile());
		while ($row = $queryResult->fetch())
		{
			yield $row['VALUE'];
		}
	}

	public static function OnAfterUserTypeDelete($field): void
	{
		$userFieldHelper = static::getInstance();
		$parseResult = $userFieldHelper->parseUserFieldEntityId($field['ENTITY_ID']);
		if (!$parseResult)
		{
			return;
		}

		/** @var TypeFactory $factory */
		/** @var int $typeId */
		[$factory, $typeId] = $parseResult;
		/** @var class-string<TypeDataManager> $dataClass */
		$dataClass = $factory->getTypeDataClass();
		$typeData = $dataClass::getById($typeId)->fetch();

		if (empty($typeData))
		{
			// non-existent or zombie. let it go
			return;
		}

		$connection = Application::getConnection();

		try
		{
			$connection->dropColumn($typeData['TABLE_NAME'], $field['FIELD_NAME']);
		}
		catch (SqlQueryException)
		{
			// no column is ok
		}

		if ($field['MULTIPLE'] === 'Y')
		{
			$utmTableName = $dataClass::getMultipleValueTableName($typeData, $field);
			if ($connection->isTableExists($utmTableName))
			{
				$connection->dropTable($utmTableName);
			}
		}
	}

	public static function onGetUserFieldValues(Event $event): EventResult
	{
		$result = new EventResult(EventResult::SUCCESS);

		$entityId = $event->getParameter('entityId');
		$userFieldHelper = static::getInstance();
		$parseResult = $userFieldHelper->parseUserFieldEntityId($entityId);
		if($parseResult)
		{
			$userFields = $event->getParameter('userFields');
			$value = $event->getParameter('value');

			/** @var TypeFactory $factory */
			[$factory, $typeId] = $parseResult;
			$dataClass = $factory->getTypeDataClass();
			$typeData = $dataClass::getById($typeId)->fetch();
			if(!$typeData)
			{
				return $result;
			}
			$itemDataClass = $factory->getItemDataClass($typeData);
			$values = $itemDataClass::getUserFieldValues($value, $userFields);

			if(!$values)
			{
				$values = [];
			}

			$result = new EventResult(EventResult::SUCCESS, [
				'values' => $values,
			]);
		}

		return $result;
	}

	public static function onUpdateUserFieldValues(Event $event): EventResult
	{
		$result = new EventResult(EventResult::UNDEFINED);

		$entityId = $event->getParameter('entityId');
		$userFieldHelper = static::getInstance();
		$parseResult = $userFieldHelper->parseUserFieldEntityId($entityId);
		if($parseResult)
		{
			$fields = $event->getParameter('fields');
			$id = $event->getParameter('id');

			/** @var TypeFactory $factory */
			[$factory, $typeId] = $parseResult;
			$dataClass = $factory->getTypeDataClass();
			$typeData = $dataClass::getById($typeId)->fetch();
			if(!$typeData)
			{
				return $result;
			}
			$itemDataClass = $factory->getItemDataClass($typeData);
			$updateResult = $itemDataClass::updateUserFieldValues($id, $fields);
			if($updateResult->isSuccess())
			{
				$result = new EventResult(EventResult::SUCCESS);
			}
			else
			{
				$result = new EventResult(EventResult::ERROR);
			}
		}

		return $result;
	}

	public static function onDeleteUserFieldValues(Event $event): EventResult
	{
		$result = new EventResult(EventResult::UNDEFINED);

		$entityId = $event->getParameter('entityId');
		$userFieldHelper = static::getInstance();
		$parseResult = $userFieldHelper->parseUserFieldEntityId($entityId);
		if($parseResult)
		{
			$id = $event->getParameter('id');

			/** @var TypeFactory $factory */
			[$factory, $typeId] = $parseResult;
			$dataClass = $factory->getTypeDataClass();
			$typeData = $dataClass::getById($typeId)->fetch();
			if(!$typeData)
			{
				return $result;
			}
			$itemDataClass = $factory->getItemDataClass($typeData);
			$updateResult = $itemDataClass::deleteUserFieldValues($id);
			if($updateResult->isSuccess())
			{
				$result = new EventResult(EventResult::SUCCESS);
			}
			else
			{
				$result = new EventResult(EventResult::ERROR);
			}
		}

		return $result;
	}
}
