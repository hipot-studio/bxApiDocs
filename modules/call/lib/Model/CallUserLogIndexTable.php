<?php
namespace Bitrix\Call\Model;

use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Entity;
use Bitrix\Main\Error;
use Bitrix\Main\DB\SqlExpression;

/**
 * Class CallUserLogIndexTable
 *
 * Fields:
 * <ul>
 * <li> USERLOG_ID int mandatory
 * <li> SEARCH_CONTENT text optional
 * <li> SEARCH_TITLE string optional
 * </ul>
 *
 * @package Bitrix\Call
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CallUserLogIndex_Query query()
 * @method static EO_CallUserLogIndex_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CallUserLogIndex_Result getById($id)
 * @method static EO_CallUserLogIndex_Result getList(array $parameters = [])
 * @method static EO_CallUserLogIndex_Entity getEntity()
 * @method static \Bitrix\Call\Model\EO_CallUserLogIndex createObject($setDefaultValues = true)
 * @method static \Bitrix\Call\Model\EO_CallUserLogIndex_Collection createCollection()
 * @method static \Bitrix\Call\Model\EO_CallUserLogIndex wakeUpObject($row)
 * @method static \Bitrix\Call\Model\EO_CallUserLogIndex_Collection wakeUpCollection($rows)
 */
class CallUserLogIndexTable extends Main\Entity\DataManager
{
	use Main\ORM\Data\Internal\DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_call_userlog_index';
	}

	public static function getMap()
	{
		return [
			'USERLOG_ID' => [
				'data_type' => 'integer',
				'primary' => true,
			],
			'SEARCH_TITLE' => [
				'data_type' => 'string',
				'validation' => [__CLASS__, 'validateTitle'],
			],
			'SEARCH_CONTENT' => [
				'data_type' => 'text',
			],
		];
	}

	public static function validateTitle()
	{
		return [
			new Entity\Validator\Length(null, 511),
		];
	}

	protected static function getMergeFields()
	{
		return ['USERLOG_ID'];
	}

	public static function merge(array $data)
	{
		$result = new Entity\AddResult();

		$helper = Application::getConnection()->getSqlHelper();
		$insertData = $data;
		$updateData = $data;
		$mergeFields = static::getMergeFields();

		foreach ($mergeFields as $field)
		{
			unset($updateData[$field]);
		}

		$versionMain = \Bitrix\Main\ModuleManager::getVersion('main');
		$isPgCompatible = (version_compare($versionMain, '24.0.0') >= 0);

		if (isset($updateData['SEARCH_CONTENT']))
		{
			if ($isPgCompatible)
			{
				$field = new SqlExpression('?v', 'SEARCH_CONTENT');
			}
			else
			{
				$field = 'SEARCH_CONTENT';
			}
			$updateData['SEARCH_CONTENT'] = new SqlExpression($helper->getConditionalAssignment($field, $updateData['SEARCH_CONTENT']));
		}

		if (isset($updateData['SEARCH_TITLE']))
		{
			if ($isPgCompatible)
			{
				$field = new SqlExpression('?v', 'SEARCH_TITLE');
			}
			else
			{
				$field = 'SEARCH_TITLE';
			}
			$updateData['SEARCH_TITLE'] = new SqlExpression($helper->getConditionalAssignment($field, $updateData['SEARCH_TITLE']));
		}

		$merge = $helper->prepareMerge(
			static::getTableName(),
			static::getMergeFields(),
			$insertData,
			$updateData
		);

		if ($merge[0] != "")
		{
			Application::getConnection()->query($merge[0]);
			$id = Application::getConnection()->getInsertedId();
			$result->setId($id);
			$result->setData($data);
		}
		else
		{
			$result->addError(new Error('Error constructing query'));
		}

		return $result;
	}

	public static function updateIndex($id, $primaryField, array $updateData): Main\ORM\Data\UpdateResult
	{
		$result = new Main\ORM\Data\UpdateResult();
		$helper = Application::getConnection()->getSqlHelper();

		if (isset($updateData[$primaryField]))
		{
			unset($updateData[$primaryField]);
		}

		if (isset($updateData['SEARCH_CONTENT']))
		{
			$updateData['SEARCH_CONTENT'] = new SqlExpression($helper->getConditionalAssignment('SEARCH_CONTENT', $updateData['SEARCH_CONTENT']));
		}

		if (isset($updateData['SEARCH_TITLE']))
		{
			$updateData['SEARCH_TITLE'] = new SqlExpression($helper->getConditionalAssignment('SEARCH_TITLE', $updateData['SEARCH_TITLE']));
		}

		$update = $helper->prepareUpdate(
			static::getTableName(),
			$updateData
		);

		if ($update[0] !== '')
		{
			$sql = "UPDATE " . static::getTableName() . " SET " . $update[0] . " WHERE " . $primaryField . " = " . $id;
			self::safeUpdateIndex($sql);
		}

		return $result;
	}

	private static function safeUpdateIndex(string $sql): void
	{
		try
		{
			Application::getConnection()->query($sql);
		}
		catch (Main\DB\SqlQueryException $exception)
		{
			if (str_starts_with($exception->getMessage(), "Mysql query error: (1022) Can't write;"))
			{
				Application::getConnection()->query($sql);

				return;
			}

			throw $exception;
		}
	}
}
