<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage socialnetwork
 * @copyright 2001-2019 Bitrix
 */
namespace Bitrix\Socialnetwork;

use Bitrix\Main\Entity;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Fields\Field;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Text\Emoji;

/**
 * Class WorkgroupTagTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkgroupTag_Query query()
 * @method static EO_WorkgroupTag_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkgroupTag_Result getById($id)
 * @method static EO_WorkgroupTag_Result getList(array $parameters = [])
 * @method static EO_WorkgroupTag_Entity getEntity()
 * @method static \Bitrix\Socialnetwork\EO_WorkgroupTag createObject($setDefaultValues = true)
 * @method static \Bitrix\Socialnetwork\EO_WorkgroupTag_Collection createCollection()
 * @method static \Bitrix\Socialnetwork\EO_WorkgroupTag wakeUpObject($row)
 * @method static \Bitrix\Socialnetwork\EO_WorkgroupTag_Collection wakeUpCollection($rows)
 */
class WorkgroupTagTable extends Entity\DataManager
{
	public const NAME_MAX_LENGTH = 255;

	public static function getTableName()
	{
		return 'b_sonet_group_tag';
	}

	public static function getMap()
	{
		return array(
			'GROUP_ID' => array(
				'data_type' => 'integer',
				'primary' => true
			),
			'GROUP' => array(
				'data_type' => '\Bitrix\Socialnetwork\Workgroup',
				'reference' => array('=this.GROUP_ID' => 'ref.ID')
			),
			'NAME' => array(
				'data_type' => 'string',
				'primary' => true,
				'validation' => [self::class, 'validateName'],
				'save_data_modification' => [Emoji::class, 'getSaveModificator'],
				'fetch_data_modification' => [Emoji::class, 'getFetchModificator'],
			)
		);
	}

	public static function prepareNameForSearch(string $name): string
	{
		return Emoji::encode(mb_strtolower(trim($name)));
	}

	/**
	 * @param list<string|int|float> $tags
	 * @return list<string>|null Normalized tags, or null when any tag exceeds storage length
	 */
	public static function prepareTagsForSave(array $tags): ?array
	{
		$prepared = [];
		foreach ($tags as $tag)
		{
			$tag = mb_strtolower(trim(Emoji::decode((string)$tag)));
			if ($tag === '')
			{
				continue;
			}

			if (mb_strlen(Emoji::encode($tag)) > self::NAME_MAX_LENGTH)
			{
				return null;
			}

			if (!in_array($tag, $prepared, true))
			{
				$prepared[] = $tag;
			}
		}

		return $prepared;
	}

	public static function validateName(): array
	{
		return [
			static function ($value, $primary, array $row, Field $field) {
				return (new LengthValidator(null, self::NAME_MAX_LENGTH))->validate(
					Emoji::encode((string)$value),
					$primary,
					$row,
					$field,
				);
			},
		];
	}

	public static function deleteByGroupId($params = array())
	{
		if (
			!is_array($params)
			|| empty($params['groupId'])
			|| intval($params['groupId']) <= 0
		)
		{
			return false;
		}

		\Bitrix\Main\Application::getConnection()->queryExecute('DELETE FROM '.self::getTableName().' WHERE GROUP_ID = '.intval($params['groupId']));
		return true;
	}

	public static function set($params = array())
	{
		if (
			!is_array($params)
			|| empty($params['groupId'])
			|| intval($params['groupId']) <= 0
			|| !isset($params['tags'])
			|| !is_array($params['tags'])
		)
		{
			return false;
		}

		$tags = self::prepareTagsForSave($params['tags']);
		if ($tags === null)
		{
			return false;
		}

		self::deleteByGroupId(array(
			'groupId' => intval($params['groupId'])
		));

		foreach($tags as $tag)
		{
			self::processAdd(array(
				'GROUP_ID' => intval($params['groupId']),
				'NAME' => $tag
			));
		}

		return true;
	}

	protected static function processAdd(array $data)
	{
		try
		{
			self::add($data);
		}
		catch (SqlQueryException $exception)
		{
			if (!self::isDuplicateKeyError($exception))
			{
				throw $exception;
			}
		}
	}

	protected static function isDuplicateKeyError(SqlQueryException $exception)
	{
		return mb_strpos($exception->getDatabaseMessage(), '(1062)') !== false;
	}
}
