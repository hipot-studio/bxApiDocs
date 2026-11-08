<?php

namespace Bitrix\Main\UserField\Internal;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\MssqlConnection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Field;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\RegExpValidator;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Main\UserField\Types\BaseType;
use Bitrix\Main\UserField\Types\FileType;

/**
 * @deprecated
 */
abstract class TypeDataManager extends DataManager
{
	public const MAXIMUM_TABLE_NAME_LENGTH = 64;

	protected static $temporaryStorage;

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new StringField('NAME'))
				->configureRequired()
				->configureUnique()
				->configureSize(100)
				->configureFormat('/^[A-Z][A-Za-z0-9]*$/')
				->addValidator(new RegExpValidator(
					'/(?<!Table)$/i'
				)),
			(new StringField('TABLE_NAME'))
				->configureRequired()
				->configureUnique()
				->configureSize(64)
				->configureFormat('/^[a-z0-9_]+$/')
				->addValidator([get_called_class(), 'validateTableExisting']),
		];
	}

	public static function getFactory(): TypeFactory
	{
		return Registry::getInstance()->getFactoryByTypeDataClass(static::class);
	}

	protected static function getTemporaryStorage(): TemporaryStorage
	{
		if(!static::$temporaryStorage)
		{
			static::$temporaryStorage = new TemporaryStorage();
		}

		return static::$temporaryStorage;
	}

	/**
	 * @param Event $event
	 * @return EventResult
	 * @throws SystemException
	 */
	public static function onAfterAdd(Event $event): EventResult
	{
		$id = $event->getParameter('id');

		static::compileEntity($id)->createDbTable();

		return new EventResult();
	}

	/**
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();

		$data = static::getByPrimary($event->getParameter('id'))->fetch();
		if ($data === false)
		{
			$result->addError(new EntityError('Item was not found'));

			return $result;
		}
		static::getTemporaryStorage()->saveData($event->getParameter('id'), $data);

		return $result;
	}

	/**
	 * @param Event $event
	 * @return EventResult
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 */
	public static function onAfterUpdate(Event $event): EventResult
	{
		$id = $event->getParameter('id');
		$data = $event->getParameter('fields');
		$oldData = static::getTemporaryStorage()->getData($id);
		if(!$oldData)
		{
			return new EventResult();
		}

		// rename table in db
		if (isset($data['TABLE_NAME']) && $data['TABLE_NAME'] !== $oldData['TABLE_NAME'])
		{
			$userFieldManager = UserFieldHelper::getInstance()->getManager();
			$connection = Application::getConnection();
			$sqlHelper = $connection->getSqlHelper();
			$connection->renameTable($oldData['TABLE_NAME'], $data['TABLE_NAME']);

			if ($connection instanceof MssqlConnection)
			{
				// rename constraint
				$connection->query(sprintf(
					"EXEC sp_rename %s, %s, 'OBJECT'",
					$sqlHelper->quote($oldData['TABLE_NAME'].'_ibpk_1'),
					$sqlHelper->quote($data['TABLE_NAME'].'_ibpk_1')
				));
			}

			// rename also uf multiple tables and its constraints, sequences, and triggers
			/** @noinspection PhpMethodOrClassCallIsNotCaseSensitiveInspection */
			foreach ($userFieldManager->getUserFields(static::getFactory()->getUserFieldEntityId($oldData['ID'])) as $field)
			{
				if ($field['MULTIPLE'] == 'Y')
				{
					$oldUtmTableName = static::getMultipleValueTableName($oldData, $field);
					$newUtmTableName = static::getMultipleValueTableName($data, $field);

					$connection->renameTable($oldUtmTableName, $newUtmTableName);
				}
			}
		}

		return new EventResult();
	}

	/**
	 * @param Event $event
	 * @return EventResult
	 * @throws SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 */
	public static function onBeforeDelete(Event $event): EventResult
	{
		$result = new EventResult();

		$id = $event->getParameter('id');
		$oldData = static::getByPrimary($id)->fetch();
		if ($oldData === false)
		{
			return $result;
		}
		static::getTemporaryStorage()->saveData($id, $oldData);

		$userFieldManager = UserFieldHelper::getInstance()->getManager();

		/** @noinspection PhpMethodOrClassCallIsNotCaseSensitiveInspection */
		$fields = $userFieldManager->getUserFields(static::getFactory()->getUserFieldEntityId($oldData['ID']));
		foreach ($fields as $name => $field)
		{
			$field['USER_TYPE'] = $field['USER_TYPE'] ?? $userFieldManager->getUserType($field['USER_TYPE_ID']);
			$fields[$name] = $field;
		}
		$connection = Application::getConnection();
		$cleanupResult = static::cleanupTypeBeforeDelete($oldData, $fields, $connection);
		if ($cleanupResult->getErrors())
		{
			$result->setErrors($cleanupResult->getErrors());

			return $result;
		}

		foreach ($fields as $field)
		{
			$connection->query("DELETE FROM b_user_field_lang WHERE USER_FIELD_ID = ".$field['ID']);
			$connection->query("DELETE FROM b_user_field WHERE ID = ".$field['ID']);

			if ($field['MULTIPLE'] === 'Y')
			{
				try
				{
					$connection->dropTable(static::getMultipleValueTableName($oldData, $field));
				}
				catch (SqlQueryException $exception)
				{
					$result->addError(new EntityError($exception->getMessage()));
				}
			}
		}

		// clear uf cache
		$managedCache = Application::getInstance()->getManagedCache();
		if(CACHED_b_user_field !== false)
		{
			$managedCache->cleanDir("b_user_field");
		}

		return $result;
	}

	protected static function cleanupTypeBeforeDelete(
		array $typeData,
		array $fields,
		Connection $connection,
	): EventResult
	{
		$result = static::cleanupTypeFieldsBeforeDelete($fields);
		if ($result->getErrors())
		{
			return $result;
		}

		static::cleanupTypeValuesBeforeDelete($typeData, $fields, $connection, false);

		return $result;
	}

	protected static function cleanupTypeValuesBeforeDelete(
		array $typeData,
		array $fields,
		Connection $connection,
		bool $stopOnError = true,
	): EventResult
	{
		$result = new EventResult();

		$fields = array_values($fields);
		for ($index = 0, $fieldCount = count($fields); $index < $fieldCount; $index++)
		{
			$field = $fields[$index];
			if (!UserFieldHelper::hasValuesDeleteHandler($field))
			{
				continue;
			}

			if (static::hasDefaultSingleFileValuesHandler($field))
			{
				$singleFileFields = [$field];
				while (isset($fields[$index + 1]))
				{
					$nextField = $fields[$index + 1];
					if (!UserFieldHelper::hasValuesDeleteHandler($nextField))
					{
						$index++;
						continue;
					}
					if (!static::hasDefaultSingleFileValuesHandler($nextField))
					{
						break;
					}

					$singleFileFields[] = $nextField;
					$index++;
				}

				$values = static::getSingleFileFieldValuesBeforeDelete($typeData, $singleFileFields, $connection);
			}
			else
			{
				$values = static::getUserFieldValuesBeforeDelete($typeData, $field, $connection);
			}

			$cleanupResult = UserFieldHelper::cleanupUserFieldValuesBeforeDelete(
				$field,
				$values,
			);
			foreach ($cleanupResult->getErrors() as $error)
			{
				$result->addError(new EntityError($error->getMessage(), $error->getCode()));
			}

			if ($stopOnError && $result->getErrors())
			{
				break;
			}
		}

		return $result;
	}

	private static function hasDefaultSingleFileValuesHandler(array $field): bool
	{
		if ($field['MULTIPLE'] !== 'N' || ($field['USER_TYPE']['BASE_TYPE'] ?? null) !== 'file')
		{
			return false;
		}

		$className = $field['USER_TYPE']['CLASS_NAME'] ?? null;
		if (!is_string($className) || !is_a($className, BaseType::class, true))
		{
			return true;
		}

		$handlerClass = (new \ReflectionMethod($className, 'onBeforeDeleteValues'))->getDeclaringClass()->getName();

		return $handlerClass === BaseType::class || $handlerClass === FileType::class;
	}

	protected static function cleanupTypeFieldsBeforeDelete(array $fields): EventResult
	{
		$result = new EventResult();

		foreach ($fields as $field)
		{
			$cleanupResult = UserFieldHelper::cleanupUserFieldDefinitionBeforeDelete($field);
			foreach ($cleanupResult->getErrors() as $error)
			{
				$result->addError(new EntityError($error->getMessage(), $error->getCode()));
			}

			if ($result->getErrors())
			{
				break;
			}
		}

		return $result;
	}

	protected static function getUserFieldValuesBeforeDelete(
		array $typeData,
		array $field,
		Connection $connection,
	): iterable
	{
		if ($field['MULTIPLE'] === 'Y')
		{
			$tableName = static::getMultipleValueTableName($typeData, $field);
			$query = new SqlExpression('SELECT ?# AS ?# FROM ?#', 'VALUE', 'VALUE', $tableName);
		}
		else
		{
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

	protected static function getSingleFileFieldValuesBeforeDelete(
		array $typeData,
		array $fields,
		Connection $connection,
	): iterable
	{
		$columns = [];
		foreach ($fields as $field)
		{
			$columns[] = (new SqlExpression('?#', $field['FIELD_NAME']))->compile();
		}

		$query = new SqlExpression(
			'SELECT ' . implode(', ', $columns) . ' FROM ?#',
			$typeData['TABLE_NAME'],
		);
		$queryResult = $connection->query($query->compile());
		while ($row = $queryResult->fetch())
		{
			foreach ($fields as $field)
			{
				yield $row[$field['FIELD_NAME']];
			}
		}
	}

	/**
	 * @param Event $event
	 * @return EventResult
	 * @throws SystemException
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 */
	public static function onAfterDelete(Event $event): EventResult
	{
		$id = $event->getParameter('id');
		$oldData = static::getTemporaryStorage()->getData($id);
		if (!$oldData)
		{
			return new EventResult();
		}

		$connection = Application::getConnection();
		if ($connection->isTableExists($oldData['TABLE_NAME']))
		{
			$connection->dropTable($oldData['TABLE_NAME']);
		}

		return new EventResult();
	}

	/**
	 * @param array|int|string|Type $type Could be an object, an array, ID or NAME of block.
	 * @return array|null
	 */
	public static function resolveType($type): ?array
	{
		if($type instanceof Type)
		{
			$type = $type->collectValues();
		}
		if (!is_array($type))
		{
			if (is_int($type) || is_numeric(mb_substr($type, 0, 1)))
			{
				// we have an id
				$type = static::getById($type)->fetch();
			}
			elseif (is_string($type) && $type !== '')
			{
				// we have a name
				$type = static::query()->addSelect('*')->where('NAME', $type)->exec()->fetch();
			}
			else
			{
				$type = null;
			}
		}
		if (empty($type))
			return null;

		if (!isset($type['ID']))
			return null;
		if (!isset($type['NAME']) || !preg_match('/^[a-z0-9_]+$/i', $type['NAME']))
			return null;
		if (empty($type['TABLE_NAME']))
			return null;

		return $type;
	}

	/**
	 * @param $type
	 * @return Entity
	 * @throws SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public static function compileEntity($type): Entity
	{
		$rawType = $type;
		$type = static::resolveType($type);
		if (empty($type))
		{
			throw new SystemException(sprintf(
				'Invalid type description `%s`.', mydump($rawType)
			));
		}
		$factory = static::getFactory();
		$type['code'] = $factory->getCode();
		/** @var PrototypeItemDataManager $itemDataClass */
		$itemDataClass = $factory->getItemPrototypeDataClass();
		$userFieldManager = UserFieldHelper::getInstance()->getManager();

		$userFields = $userFieldManager->getUserFields($factory->getUserFieldEntityId($type['ID']));

		$entityName = $type['code'] . '_items_' . $type['ID'];
		$entityClassName = $entityName.'Table';
		$entityTableName = $type['TABLE_NAME'];
		if(class_exists($entityClassName))
		{
			// rebuild if it already exists
			Entity::destroy($entityClassName);
			$entity = Entity::getInstance($entityClassName);
		}
		else
		{
			$entity = Entity::compileEntity($entityName, [], [
				'table_name' => $entityTableName,
				'parent' => $itemDataClass,
				'object_parent' => $factory->getItemParentClass(),
				//'namespace' => __NAMESPACE__,
			]);
		}
		Registry::getInstance()->registerTypeByEntity($entity, $type);

		foreach ($userFields as $userField)
		{
			if ($userField['MULTIPLE'] == 'N')
			{
				// just add single field
				$params = [
					'required' => ($userField['MANDATORY'] === 'Y')
				];
				$field = $userFieldManager->getEntityField($userField, $userField['FIELD_NAME'], $params);
				$entity->addField($field);
				foreach ($userFieldManager->getEntityReferences($userField, $field) as $reference)
				{
					$entity->addField($reference);
				}
			}
			else
			{
				// build utm entity
				static::compileUtmEntity($entity, $userField);
			}
		}

		return $entity;
	}

	/**
	 * @param Entity $typeEntity
	 * @param $userField
	 * @return mixed
	 */
	protected static function compileUtmEntity(Entity $typeEntity, $userField): Entity
	{
		$userFieldManager = UserFieldHelper::getInstance()->getManager();

		// build utm entity
		/** @var PrototypeItemDataManager $itemDataClass */
		$itemDataClass = $typeEntity->getDataClass();
		$typeData = $itemDataClass::getType();

		$utmClassName = static::getUtmEntityClassName($typeEntity, $userField);
		$utmTableName = static::getMultipleValueTableName($typeData, $userField);

		if (class_exists($utmClassName.'Table'))
		{
			// rebuild if it already exists
			Entity::destroy($utmClassName.'Table');
			$utmEntity = Entity::getInstance($utmClassName);
		}
		else
		{
			// create entity from scratch
			$utmEntity = Entity::compileEntity($utmClassName, [], [
				'table_name' => $utmTableName,
				//'namespace' => __NAMESPACE__,
			]);
		}

		// main fields
		$utmValueField = $userFieldManager->getEntityField($userField, 'VALUE');

		$utmEntityFields = array(
			new IntegerField('ID'),
			$utmValueField
		);

		// references
		$references = $userFieldManager->getEntityReferences($userField, $utmValueField);

		foreach ($references as $reference)
		{
			$utmEntityFields[] = $reference;
		}

		foreach ($utmEntityFields as $field)
		{
			$utmEntity->addField($field);
		}

		// add original entity reference
		$referenceField = new Reference(
			'OBJECT',
			$typeEntity,
			['=this.ID' => 'ref.ID']
		);

		$utmEntity->addField($referenceField);

		// add short alias for back-reference
		$aliasField = new ExpressionField(
			$userField['FIELD_NAME'].'_SINGLE',
			'%s',
			$utmEntity->getFullName().':'.'OBJECT.VALUE',
			array(
				'data_type' => get_class($utmEntity->getField('VALUE')),
				'required' => $userField['MANDATORY'] == 'Y'
			)
		);

		$typeEntity->addField($aliasField);

		$cacheField = new ArrayField($userField['FIELD_NAME']);
		$cacheField->configureRequired($userField['MANDATORY'] === 'Y');
		\Bitrix\Main\UserField\Internal\MultipleFieldSerializer::setMultipleFieldSerialization($cacheField, $utmValueField, $userField);
		$typeEntity->addField($cacheField);

		return $utmEntity;
	}

	/**
	 * @param Entity $typeEntity
	 * @param $userField
	 * @return string
	 */
	public static function getUtmEntityClassName(Entity $typeEntity, array $userField): string
	{
		return $typeEntity->getName() . 'Utm' . StringHelper::snake2camel($userField['FIELD_NAME']);
	}

	/**
	 * @param $type
	 * @param $userField
	 * @return string
	 */
	public static function getMultipleValueTableName(array $type, array $userField): string
	{
		$tableName = $type['TABLE_NAME'] . '_' . mb_strtolower($userField['FIELD_NAME']);

		if (mb_strlen($tableName) > static::MAXIMUM_TABLE_NAME_LENGTH && !empty($userField['ID']))
		{
			$tableName = $type['TABLE_NAME'] . '_' . $userField['ID'];
		}

		return $tableName;
	}

	public static function validateTableExisting($value, $primary, array $row, Field $field)
	{
		$checkName = null;

		if (empty($primary))
		{
			// new row
			$checkName = $value;
		}
		else
		{
			$factory = static::getFactory();
			$typeDataClass = $factory->getTypeDataClass();
			// update row
			$oldData = $typeDataClass::getByPrimary($primary)->fetch();

			if ($value != $oldData['TABLE_NAME'])
			{
				// table name has been changed for existing row
				$checkName = $value;
			}
		}

		if (!empty($checkName))
		{
			if (Application::getConnection()->isTableExists($checkName))
			{
				Loc::loadLanguageFile(__DIR__.'/highloadblock.php');
				return Loc::getMessage('HIGHLOADBLOCK_HIGHLOAD_BLOCK_ENTITY_TABLE_NAME_ALREADY_EXISTS',
					['#TABLE_NAME#' => $value]
				);
			}
		}

		return true;
	}

	public static function getObjectParentClass(): string
	{
		return Type::class;
	}
}
