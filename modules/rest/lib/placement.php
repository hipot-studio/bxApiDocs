<?php
namespace Bitrix\Rest;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Relations\OneToMany;
use Bitrix\Main\EventManager;
use Bitrix\Main\Event;
use Bitrix\Rest\Internal\Service\Placement\HandlerReader;
use Bitrix\Rest\UserField\Callback;

Loc::loadMessages(__FILE__);

/**
 * Class PlacementTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> APP_ID int optional
 * <li> PLACEMENT string(255) mandatory
 * <li> PLACEMENT_HANDLER string(255) mandatory
 * <li> TITLE string(255) optional
 * <li> COMMENT string(255) optional
 * <li> DATE_CREATE datetime optional
 * </ul>
 *
 * @package Bitrix\Rest
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Placement_Query query()
 * @method static EO_Placement_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Placement_Result getById($id)
 * @method static EO_Placement_Result getList(array $parameters = [])
 * @method static EO_Placement_Entity getEntity()
 * @method static \Bitrix\Rest\EO_Placement createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\EO_Placement_Collection createCollection()
 * @method static \Bitrix\Rest\EO_Placement wakeUpObject($row)
 * @method static \Bitrix\Rest\EO_Placement_Collection wakeUpCollection($rows)
 */
class PlacementTable extends Main\Entity\DataManager
{
	public const PREFIX_EVENT_ON_AFTER_ADD = 'onAfterPlacementAdd::';
	public const PREFIX_EVENT_ON_AFTER_DELETE = 'onAfterPlacementDelete::';

	public const DEFAULT_USER_ID_VALUE = 0;

	private const OWNER_TRIPLE_FIELDS = [
		'MODULE_ID',
		'ENTITY_TYPE',
		'ENTITY_ID',
	];

	private const OWNER_VALUE_MAX_LENGTH = 50;

	const PLACEMENT_DEFAULT = 'DEFAULT';

	const ERROR_PLACEMENT_NOT_FOUND = 'ERROR_PLACEMENT_NOT_FOUND';
	const ERROR_PLACEMENT_MAX_COUNT = 'ERROR_PLACEMENT_MAX_COUNT';
	const ERROR_PLACEMENT_USER_MODE = 'ERROR_PLACEMENT_USER_MODE';

	const CACHE_TTL = 86400;
	const CACHE_DIR = 'rest/placement';
	private static $beforeDeleteList = [];

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_rest_placement';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			),
			'APP_ID' => array(
				'data_type' => 'integer',
				'nullable' => true,
			),
			'USER_ID' => array(
				'data_type' => 'integer',
			),
			'PLACEMENT' => array(
				'data_type' => 'string',
				'required' => true,
			),
			'PLACEMENT_HANDLER' => array(
				'data_type' => 'string',
				'required' => true,
			),
			/**
			 * @deprecated
			 * Use LANG.GROUP_NAME
			 */
			'GROUP_NAME' => array(
				'data_type' => 'string',
			),
			'ICON_ID' => array(
				'data_type' => 'integer',
			),
			/**
			 * @deprecated
			 * Use LANG.TITLE
			 */
			'TITLE' => array(
				'data_type' => 'string',
			),
			/**
			 * @deprecated
			 * Use LANG.DESCRIPTION
			 */
			'COMMENT' => array(
				'data_type' => 'string',
			),
			'DATE_CREATE' => array(
				'data_type' => 'datetime',
			),
			'ADDITIONAL' => array(
				'data_type' => 'string',
			),
			'OPTIONS' => new ArrayField('OPTIONS'),
			'REST_APP' => array(
				'data_type' => 'Bitrix\Rest\AppTable',
				'reference' => array('=this.APP_ID' => 'ref.ID'),
			),
			(new OneToMany(
				'LANG_ALL',
				\Bitrix\Rest\PlacementLangTable::class,
				'PLACEMENT'
			))->configureJoinType('left'),
			'MODULE_ID' => new StringField('MODULE_ID'),
			'ENTITY_TYPE' => new StringField('ENTITY_TYPE'),
			'ENTITY_ID' => new StringField('ENTITY_ID'),
		);
	}

	/**
	 * Returns list of placement handlers. Use \Bitrix\Rest\PlacementTable::getHandlersList.
	 *
	 * @param string $placement Placement ID.
	 *
	 * @return Main\DB\Result
	 */
	public static function getHandlers($placement, int $userId = null)
	{
		return (new Internal\Service\Placement\HandlerQuery())->getLegacyHandlers(
			(string)$placement,
			Internal\Service\Placement\HandlerReader::resolveUserId($userId),
		);
	}

	/**
	 * Removes all application placement handlers.
	 *
	 * @param int $appId Application ID.
	 *
	 * @return Main\DB\Result
	 */
	public static function deleteByApp($appId)
	{
		$appId = (int)$appId;

		if ($appId <= 0)
		{
			throw new Main\ArgumentException(
				'Deleting by non-positive application id is not allowed (' . static::getTableName() . ').',
				'appId'
			);
		}

		(new Internal\Service\Embedding\EmbeddingAccessSynchronizer())->deleteCodesOfApp($appId);

		return static::deleteByFilter(['=APP_ID' => $appId], notifySubscribers: true);
	}

	public static function deleteByFilter(
		array|Main\ORM\Query\Filter\ConditionTree $filter,
		bool $notifySubscribers = false,
	): Main\DB\Result
	{
		$entity = static::getEntity();
		$table = static::getTableName();

		$where = Main\ORM\Query\Query::buildFilterSql($entity, $filter);
		if ($where === '')
		{
			throw new Main\ArgumentException("Deleting by empty filter is not allowed, use truncate ({$table}).", 'filter');
		}

		$where = ' where ' . $where;
		$rows = static::collectRowsToDelete($where);

		static::onBeforeDeleteByFilter($rows);

		$queryResult = $entity->getConnection()->query("delete from {$table} {$where}");

		static::cleanCache();

		try
		{
			static::onAfterDeleteByFilter($rows, $notifySubscribers);
		}
		finally
		{
			static::clearHandlerCache();
		}

		return $queryResult;
	}

	protected static function onBeforeDeleteByFilter(array $rows): void
	{
		PlacementLangTable::deleteByPlacementIds(array_column($rows, 'ID'));
	}

	protected static function onAfterDeleteByFilter(array $rows, bool $notifySubscribers): void
	{
		static::deleteIcons(array_column($rows, 'ICON_ID'));

		(new Internal\Service\Embedding\EmbeddingAccessSynchronizer())
			->deleteCodesOfEmbeddings(array_column($rows, 'ID'))
		;

		if ($notifySubscribers)
		{
			static::sendPlacementDeleteEvents($rows);
		}
	}

	private static function collectRowsToDelete(string $where): array
	{
		$result = static::getEntity()->getConnection()->query(
			'SELECT ID, ICON_ID, PLACEMENT FROM ' . static::getTableName() . $where
		);

		$rows = [];
		while ($row = $result->fetch())
		{
			$rows[] = [
				'ID' => (int)$row['ID'],
				'ICON_ID' => (int)($row['ICON_ID'] ?? 0),
				'PLACEMENT' => (string)$row['PLACEMENT'],
			];
		}

		return $rows;
	}

	private static function sendPlacementDeleteEvents(array $rows): void
	{
		foreach ($rows as $row)
		{
			EventManager::getInstance()->send(
				new Event(
					'rest',
					static::PREFIX_EVENT_ON_AFTER_DELETE . $row['PLACEMENT'],
					[
						'ID' => $row['ID'],
						'PLACEMENT' => $row['PLACEMENT'],
					]
				)
			);
		}
	}

	/**
	 * @param int[] $iconIds
	 */
	private static function deleteIcons(array $iconIds): void
	{
		foreach ($iconIds as $iconId)
		{
			if ($iconId > 0)
			{
				\CFile::Delete($iconId);
			}
		}
	}

	/**
	 * Returns cached list of placement handlers.
	 *
	 * @param string $placement Placement ID
	 * @param bool $skipInstallCheck Optional flag to allow placement from apps with unfinished install
	 *
	 * @return array
	 */
	public static function getHandlersList($placement, $skipInstallCheck = false, ?int $userId = null)
	{
		return (new HandlerReader())->readHandlers(
			(string)$placement,
			(bool)$skipInstallCheck,
			$userId,
		);
	}

	public static function getAccessibleHandlerIds($placement, ?int $userId = null): array
	{
		return (new HandlerReader())->readAccessibleIds((string)$placement, $userId);
	}

	/**
	 * Return default placements title
	 * @param int $placementId
	 * @param null $language
	 *
	 * @return string|null
	 */
	public static function getDefaultTitle(int $placementId, $language = null): ?string
	{
		return Loc::getMessage(
			'REST_PLACEMENT_DEFAULT_TITLE',
			[
				'#ID#' => $placementId,
			],
			$language
		);
	}

	public static function clearHandlerCache()
	{
		(new Internal\Service\Placement\HandlerCache(
			Main\Application::getInstance()->getManagedCache(),
			static::CACHE_TTL,
			static::CACHE_DIR,
		))->clear();

		if (defined('BX_COMP_MANAGED_CACHE'))
		{
			global $CACHE_MANAGER;
			$CACHE_MANAGER->clearByTag('intranet_menu_binding');
		}
	}

	public static function onBeforeUpdate(Main\Entity\Event $event)
	{
		$owner = static::resolveOwner($event);

		$result = static::checkUniq($event, false, $owner->getData());
		static::applyOwner($result, $owner);
		static::modifyFields($event, $result);

		return $result;
	}

	public static function onBeforeAdd(Main\Entity\Event $event)
	{
		$owner = static::resolveOwner($event);

		$result = static::checkUniq($event, true, $owner->getData());
		static::applyOwner($result, $owner);
		static::modifyFields($event, $result);

		return $result;
	}

	public static function onBeforeDelete(Main\Entity\Event $event)
	{
		$result = new Main\ORM\EventResult();
		$id = $event->getParameter('id');
		$id = (int)$id['ID'];
		$res = static::getList(
			[
				'filter' => [
					'=ID' => $id,
				],
				'select' => [
					'ID',
					'APP_ID',
					'PLACEMENT',
					'PLACEMENT_HANDLER',
					'ICON_ID',
					'ADDITIONAL',
					'OPTIONS',
				],
				'limit' => 1,
			],
		);
		if ($placement = $res->fetch())
		{
			static::$beforeDeleteList[$placement['ID']] = $placement;
			if ((int)$placement['ICON_ID'] > 0)
			{
				\CFile::Delete((int)$placement['ICON_ID']);
			}
		}
		PlacementLangTable::deleteByPlacement($id);

		return $result;
	}

	public static function onAfterAdd(Main\Entity\Event $event)
	{
		$fields = $event->getParameter('fields');

		if (!empty($fields['PLACEMENT']) && (int)$fields['APP_ID'] > 0)
		{
			(new Internal\Service\Embedding\EmbeddingAccessSynchronizer())
				->syncEmbedding((int)$event->getParameter('id'))
			;

			$app = AppTable::getByClientId((int)$fields['APP_ID']);
			if ($app['ACTIVE'] === AppTable::ACTIVE && $app['INSTALLED'] === AppTable::INSTALLED)
			{
				$data = new Event(
					'rest',
					static::PREFIX_EVENT_ON_AFTER_ADD . $fields['PLACEMENT'],
					[
						'ID' => $event->getParameter('id'),
						'PLACEMENT' => $fields['PLACEMENT'],
					]
				);
				EventManager::getInstance()->send($data);
			}
		}

		static::clearHandlerCache();
	}

	public static function onAfterUpdate(Main\Entity\Event $event)
	{
		if (static::isOwnerTouched($event->getParameter('fields')))
		{
			(new Internal\Service\Embedding\EmbeddingAccessSynchronizer())
				->syncEmbedding(static::getEventRowId($event))
			;
		}

		static::clearHandlerCache();
	}

	public static function onAfterDelete(Main\Entity\Event $event)
	{
		$id = $event->getParameter('id');
		$id = (int)$id['ID'];

		try
		{
			if ($id > 0)
			{
				(new Internal\Service\Embedding\EmbeddingAccessSynchronizer())->deleteCodesOfEmbedding($id);

				$data = new Event(
					'rest',
					static::PREFIX_EVENT_ON_AFTER_DELETE . static::$beforeDeleteList[$id]['PLACEMENT'],
					[
						'ID' => $id,
						'PLACEMENT' => static::$beforeDeleteList[$id]['PLACEMENT'],
					]
				);
				unset(static::$beforeDeleteList[$id]);
				EventManager::getInstance()->send($data);
			}
		}
		finally
		{
			static::clearHandlerCache();
		}
	}

	protected static function checkUniq(Main\Entity\Event $event, $add = false, array $owner = [])
	{
		$result = new Main\Entity\EventResult();
		$data = $event->getParameter('fields');

		$filter = static::getUniqFilter($data, $owner);
		$taken = !empty($filter) && (bool)static::getList(array(
			'filter' => $filter,
			'select' => array('ID')
		))->fetch();

		if ($taken)
		{
			$result->addError(new Main\Entity\EntityError(
				"Handler already binded"
			));

			return $result;
		}

		if ($add)
		{
			$result->modifyFields(array(
				"DATE_CREATE" => new Main\Type\DateTime(),
			));
		}

		return $result;
	}

	private static function getUniqFilter(array $data, array $owner): array
	{
		$conditions = [];
		$appId = array_key_exists('APP_ID', $owner)
			? (int)$owner['APP_ID']
			: (int)($data['APP_ID'] ?? 0);
		$hasHandler = array_key_exists('PLACEMENT', $data) && array_key_exists('PLACEMENT_HANDLER', $data);

		if (empty($owner) && $hasHandler)
		{
			$conditions[] = [
				'=APP_ID' => $data['APP_ID'] ?? null,
				'=PLACEMENT' => $data['PLACEMENT'],
				'=PLACEMENT_HANDLER' => $data['PLACEMENT_HANDLER'],
			];
		}
		elseif ($appId > 0 && $hasHandler)
		{
			$conditions[] = [
				'=APP_ID' => $appId,
				'=PLACEMENT' => $data['PLACEMENT'],
				'=PLACEMENT_HANDLER' => $data['PLACEMENT_HANDLER'],
			];
		}

		if (!empty($data['ADDITIONAL']))
		{
			$conditions[] = ['=ADDITIONAL' => $data['ADDITIONAL']];
		}

		if (empty($conditions))
		{
			return [];
		}

		return array_merge(['LOGIC' => 'OR'], $conditions);
	}

	private static function modifyFields(Main\ORM\Event $event, Main\ORM\EventResult $result)
	{
		if ($result->getType() !== Main\Entity\EventResult::ERROR)
		{
			$fieldChanged = [];
			$data = array_merge($event->getParameter('fields'), $result->getModified());
			if (array_key_exists('ICON', $data))
			{
				if ($str = \CFile::CheckImageFile($data['ICON']))
				{
					$result->addError(new Main\ORM\Fields\FieldError(static::getEntity()->getField('ICON_ID'), $str));
				}
				else
				{
					\CFile::ResizeImage($data['ICON'], [
						'width' => Main\Config\Option::get('rest', 'icon_size', 100),
						'height' => Main\Config\Option::get('rest', 'icon_size', 100)]);
					$data['ICON']['MODULE_ID'] = 'rest';
					if ($id = $event->getParameter('id'))
					{
						$id = is_integer($id) ? $id : $id['ID'];
						if ($id > 0 && ($icon = PlacementTable::getById($id)->fetchObject()))
						{
							$data['ICON']['old_file'] = $icon->getIconId();
						}
					}
					if (\CFile::SaveForDB($data, 'ICON', 'rest/placementicon'))
					{
						$fieldChanged['ICON_ID'] = $data['ICON'];
					}
				}
				$result->unsetField('ICON');
			}

			if (array_key_exists('DESCRIPTION', $data))
			{
				$fieldChanged['COMMENT'] = $data['DESCRIPTION'];
				$result->unsetField('DESCRIPTION');
			}

			if (!empty($fieldChanged))
			{
				$result->modifyFields(array_merge($result->getModified(), $fieldChanged));
			}
		}

		return $result;
	}

	private static function resolveOwner(Main\ORM\Event $event): Main\Result
	{
		$result = new Main\Result();
		$fields = $event->getParameter('fields');

		if (!static::isOwnerTouched($fields))
		{
			return $result;
		}

		$errors = static::getOwnerErrors($fields);
		if (!empty($errors))
		{
			return $result->addErrors($errors);
		}

		$ownerFields = Internal\Service\Embedding\EmbeddingOwnerResolver::normalize($fields);
		if (empty($ownerFields))
		{
			return $result->addError(new Main\Entity\EntityError('Invalid embedding owner'));
		}

		return $result->setData($ownerFields);
	}

	private static function getOwnerErrors(array $fields): array
	{
		$errors = [];

		if (static::isOwnerTripleTouched($fields) && !static::isOwnerTripleComplete($fields))
		{
			return [
				new Main\Entity\EntityError(
					'MODULE_ID, ENTITY_TYPE and ENTITY_ID must be changed together',
				),
			];
		}

		foreach (self::OWNER_TRIPLE_FIELDS as $fieldName)
		{
			if (mb_strlen((string)($fields[$fieldName] ?? '')) > self::OWNER_VALUE_MAX_LENGTH)
			{
				$errors[] = new Main\Entity\EntityError(
					$fieldName . ' is longer than ' . self::OWNER_VALUE_MAX_LENGTH . ' characters',
				);
			}
		}

		return $errors;
	}

	private static function applyOwner(Main\ORM\EventResult $result, Main\Result $owner): void
	{
		foreach ($owner->getErrors() as $error)
		{
			$result->addError($error);
		}
		if ($result->getType() === Main\Entity\EventResult::ERROR)
		{
			return;
		}

		$ownerFields = $owner->getData();
		if (empty($ownerFields))
		{
			return;
		}

		$result->modifyFields(array_merge($result->getModified(), $ownerFields));
	}

	private static function isOwnerTouched(array $fields): bool
	{
		return array_key_exists('APP_ID', $fields) || static::isOwnerTripleTouched($fields);
	}

	private static function isOwnerTripleTouched(array $fields): bool
	{
		foreach (self::OWNER_TRIPLE_FIELDS as $fieldName)
		{
			if (array_key_exists($fieldName, $fields))
			{
				return true;
			}
		}

		return false;
	}

	private static function isOwnerTripleComplete(array $fields): bool
	{
		foreach (self::OWNER_TRIPLE_FIELDS as $fieldName)
		{
			if (empty($fields[$fieldName]))
			{
				return false;
			}
		}

		return true;
	}

	private static function getEventRowId(Main\ORM\Event $event): int
	{
		$id = $event->getParameter('id');

		return (int)(is_array($id) ? ($id['ID'] ?? 0) : $id);
	}
}
