<?php

namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Crm\CallList\Internals\CallListItemTable;
use Bitrix\Crm\CallList\Internals\CallListTable;
use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Security\Controller\Base as SecurityController;
use Bitrix\Crm\Security\Manager as SecurityManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Main;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

Loc::loadMessages(__FILE__);

class CallList extends Base
{
	const PROVIDER_ID = 'CALL_LIST';
	const TYPE_CALL_LIST = 'CALL_LIST';

	/** The types of the items a call list resolves: a call list of another type has no items to read. */
	private const TYPES_OF_ITEMS = [
		\CCrmOwnerType::Lead,
		\CCrmOwnerType::Contact,
		\CCrmOwnerType::Company,
		\CCrmOwnerType::Deal,
		\CCrmOwnerType::Quote,
		\CCrmOwnerType::Invoice,
	];

	/**
	 * The lists of the items the rule drops the items of a call list by, where they build the condition of the
	 * rights such a list puts into its query. The batch decides the call lists of these types alone: a type that
	 * is not here is left to the rule one by one, so the batch never answers without that condition.
	 */
	private const LISTS_OF_ITEMS = [
		\CCrmOwnerType::Lead => \CCrmLead::class,
		\CCrmOwnerType::Contact => \CCrmContact::class,
		\CCrmOwnerType::Company => \CCrmCompany::class,
		\CCrmOwnerType::Deal => \CCrmDeal::class,
		\CCrmOwnerType::Quote => \CCrmQuote::class,
	];

	/**
	 * How many items of a call list the batch reads before the rest of them: enough for a user who may read a part
	 * of the items to find a readable one among them, and few enough to cost nothing next to the whole call list.
	 */
	private const FIRST_ITEMS = 20;

	/**
	 * How many of the first items of a call list are looked at before the others of them: looking at an item reads
	 * its permission attributes, which costs the more the more responsible persons the items have.
	 */
	private const FIRST_ITEMS_LOOKED_AT_ONCE = 1;

	/**
	 * Up to which item of a call list each portion reads it, in the order of the primary key of the items: the
	 * first items, then wider and wider portions for the call lists the portions before left undecided. The last
	 * one ends an item past the limit of a call list the product builds, so that such a call list is found out of
	 * items by it; a call list that has more is read on by its key ({@see self::decideByAllItems()}).
	 */
	private const ITEMS_BY_PORTIONS = [
		self::FIRST_ITEMS,
		100,
		\Bitrix\Crm\CallList\CallList::ITEMS_LIMIT + 1,
	];

	/**
	 * How many rows of items one query reads at most: the call lists of a portion are read in groups of so many
	 * that their rows fit, and what a group preloaded to look at them is released before the next one is read.
	 */
	private const ITEMS_READ_AT_ONCE = 10000;

	/** @var array<int, array<int, bool>>|null user id => call list id => whether it may be read; null when not kept */
	private static ?array $readVerdicts = null;

	/**
	 * @var array<int, array<int, string|false>> the user of the hit => the type of the items => what the list
	 * of the items builds as the condition of the rights, kept as long as the verdicts are
	 */
	private static array $conditionsOfLists = [];

	/** @var bool|null whether the database has window functions; null until asked, then kept for the process */
	private static ?bool $windowFunctions = null;

	/**
	 * @inheritdoc
	 */
	public static function getId()
	{
		return self::PROVIDER_ID;
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_ACTIVITY_PROVIDER_CALL_LIST_TITLE');
	}

	/**
	 * @param array $activity
	 * @return string
	 */
	public static function getPlannerTitle(array $activity)
	{
		return Loc::getMessage('CRM_ACTIVITY_PROVIDER_CALL_LIST_TITLE');
	}

	/**
	 * Returns supported provider's types
	 * @return array
	 */
	public static function getTypes()
	{
		return array(
			array(
				'NAME' => Loc::getMessage('CRM_ACTIVITY_PROVIDER_CALL_LIST_TITLE'),
				'PROVIDER_ID' => static::PROVIDER_ID,
				'PROVIDER_TYPE_ID' => static::TYPE_CALL_LIST,
			),
		);
	}

	/**
	 * @param array $params Activity params.
	 * @return array Actions list.
	 */
	public static function getPlannerActions(array $params = null)
	{
		if($params['OWNER_TYPE_ID'] > 0 && $params['OWNER_ID'] > 0)
		{
			return array();
		}
		else if(\Bitrix\Crm\CallList\CallList::isAvailable())
		{
			return array(
				array(
					'ACTION_ID' => static::getId().'_ACTIVITY',
					'NAME' => Loc::getMessage('CRM_ACTIVITY_PROVIDER_CALL_LIST_TITLE'),
					'PROVIDER_ID' => static::getId(),
					'PROVIDER_TYPE_ID' => static::TYPE_CALL_LIST
				)
			);
		}
		else
		{
			return array();
		}
	}

	/**
	 * @return int
	 */
	public static function prepareToolbarButtons(array &$buttons, array $params = null)
	{
		if(\Bitrix\Crm\CallList\CallList::isAvailable())
		{
			return 0;
		}
		else if($params['OWNER_TYPE_ID'] > 0 && $params['OWNER_ID'] > 0)
		{
			return 0;
		}
		else
		{
			$buttons[] = array(
				'TEXT' => Loc::getMessage('CRM_ACTIVITY_PROVIDER_CALL_LIST_TITLE'),
				'TITLE' => Loc::getMessage('CRM_ACTIVITY_PROVIDER_CALL_LIST_TITLE'),
				'ONCLICK' => \Bitrix\Crm\Restriction\RestrictionManager::getCallListRestriction()->prepareInfoHelperScript(),
				'ICON' => "btn-new"
			);
			return 1;
		}
	}

	public static function getFieldsForEdit(array $activity)
	{
		global $APPLICATION;
		ob_start();
		$APPLICATION->IncludeComponent(
			'bitrix:crm.activity.call_list',
			'',
			array(
				'ACTIVITY' => $activity,
				'ACTION' => 'EDIT'
			)
		);

		return array(
			array(
				'HTML' => ob_get_clean()
			),
		);
	}

	public static function getAdditionalFieldsForEdit(array $activity)
	{
		return array(
			array('TYPE' => 'RESPONSIBLE'),
			array(
				'TYPE' => 'HTML',
				'HTML' => static::getWebformsHtml($activity)
			)
		);
	}

	public static function getWebformsHtml($activity)
	{
		$callList = array(
			'WEBFORM_ID' => 0
		);
		if((int)($activity['ASSOCIATED_ENTITY_ID'] ?? 0) > 0)
		{
			try
			{
				$callListEntity = \Bitrix\Crm\CallList\CallList::createWithId((int)$activity['ASSOCIATED_ENTITY_ID']);
				$callList['WEBFORM_ID'] = $callListEntity->getWebformId();
			}
			catch (Main\SystemException $e)
			{
				if (!in_array((int)$e->getCode(), [403, 404], true))
				{
					throw $e;
				}
			}
		}

		$forms = FormTable::getDefaultTypeList(array(
			'select' => array('ID', 'NAME'),
			'filter' => array('ACTIVE' => 'Y')
		));
		$result ='
			<div class="crm-activity-popup-info-person-detail-calendar" style="margin-bottom: 20px;">
				<input type="hidden" name="useWebform" value="N">
				<input type="checkbox" name="useWebform" value="Y" class="crm-activity-popup-timeline-checkbox" id="crm-activity-popup-use-webform" '.($callList['WEBFORM_ID'] > 0 ? 'checked' : '').'>
				<label for="crm-activity-popup-use-webform">'.GetMessage('CRM_CALL_LIST_USE_WEBFORM').':</label>
				<select name="webformId" class="crm-activity-popup-info-webform-input">
				';

				foreach ($forms as $form)
				{
					$result .= '<option value="'.htmlspecialcharsbx($form['ID']).'" '.($callList['WEBFORM_ID'] == $form['ID'] ? 'selected' : '').'>'.htmlspecialcharsbx($form['NAME']).'</option>';
				}

		$result .= '
				</select>
			</div>';
		return $result;
	}

	/**
	 * @inheritdoc
	 */
	public static function renderView(array $activity)
	{
		global $APPLICATION;
		ob_start();
		$APPLICATION->IncludeComponent(
			'bitrix:crm.activity.call_list',
			'',
			array(
				'ACTIVITY' => $activity,
				'ACTION' => 'VIEW'
			)
		);
		return ob_get_clean();
	}

	public static function checkOwner()
	{
		return false;
	}

	public static function postForm(array &$activity, array $formData)
	{
		$result = new Main\Result();
		$callListId = (int)$formData['callListId'];
		$callListSubject = (string)$formData['callListSubject'];
		$callListDescription = (string)$formData['callListDescription'];

		if($callListId === 0)
		{
			$result->addError(new Main\Error(Loc::getMessage('CRM_CALL_LIST_NOT_CREATED_ERROR')));
			return $result;
		}

		if($callListSubject == '')
		{
			$result->addError(new Main\Error(Loc::getMessage('CRM_CALL_LIST_SUBJECT_EMPTY')));
			return $result;
		}

		try
		{
			$callList = \Bitrix\Crm\CallList\CallList::createWithId($callListId);
		}
		catch (\Bitrix\Main\SystemException $e)
		{
			$result->addError(new Main\Error(Loc::getMessage('CRM_CALL_LIST_NOT_CREATED_ERROR')));
			return $result;
		}

		if($callList->getItemsCount() == 0)
		{
			$result->addError(new Main\Error(Loc::getMessage('CRM_CALL_LIST_NOT_CREATED_ERROR')));
			return $result;
		}

		$webformId = $formData['useWebform'] === 'Y' ? (int)$formData['webformId'] : null;
		$callList->setWebformId($webformId);
		$callList->persist();

		$activity['ASSOCIATED_ENTITY_ID'] = $callListId;
		//$activity['BINDINGS'] = $callList->convertItemsToBindings();
		$activity['SUBJECT'] = $callListSubject;
		$activity['DESCRIPTION'] = $callListDescription;
		$activity['OWNER_TYPE_ID'] = \CCrmOwnerType::CallList;
		$activity['OWNER_ID'] = $callListId;

		$activity['BINDINGS'] = array(
			array(
				'OWNER_TYPE_ID' => \CCrmOwnerType::CallList,
				'OWNER_ID' => $callListId
			)
		);

		return $result;
	}

	/**
	 * Returns provider types for usage in the activities filter.
	 * @return array
	 */
	public static function getTypesFilterPresets()
	{
		// Call presets is already in filter (compatible TYPE_ID = \CCrmActivityType::Call)
		// Add Callback only.
		return array(
			array(
				'NAME' => Loc::getMessage('CRM_ACTIVITY_PROVIDER_CALL_LIST_TITLE'),
				'PROVIDER_TYPE_ID' =>  static::TYPE_CALL_LIST,
			)
		);
	}

	public static function onAfterAdd($activityFields, array $params = null)
	{
		if($activityFields['AUTHOR_ID'] !== $activityFields['RESPONSIBLE_ID'])
			static::notify($activityFields);
	}

	public static function notify($activityFields): void
	{
		if (!Main\Loader::includeModule('im'))
		{
			return;
		}

		$subject = $activityFields['SUBJECT'] ?? '';
		$url = \CCrmOwnerType::GetEntityShowPath(\CCrmOwnerType::Activity, $activityFields['ID'] ?? 0);

		$notifyMessageCallback = static fn (?string $languageId = null) =>
			Loc::getMessage(
				'CRM_CALL_LIST_RESPONSIBLE_IM_NOTIFY',
				[ '#title#' => '<a href="'.$url.'">'. htmlspecialcharsbx($subject) .'</a>' ],
				$languageId,
			)
		;

		$notifyMessageOutCallback = static fn (?string $languageId = null) =>
			Loc::getMessage(
				'CRM_CALL_LIST_RESPONSIBLE_IM_NOTIFY',
				[ '#title#' => htmlspecialcharsbx($subject) ],
				$languageId,
			)
		;

		$notifySubjectCallback = static fn (?string $languageId = null) =>
			Loc::getMessage(
				'CRM_CALL_LIST_RESPONSIBLE_IM_NOTIFY_SUBJECT',
				[ '#title#' => htmlspecialcharsbx($subject) ],
				$languageId,
			)
		;

		$notification = array(
			"MESSAGE_TYPE" => IM_MESSAGE_SYSTEM,
			"TO_USER_ID" => (int)($activityFields['RESPONSIBLE_ID'] ?? 0),
			"FROM_USER_ID" => (int)($activityFields['AUTHOR_ID'] ?? 0),
			"NOTIFY_TYPE" => IM_NOTIFY_FROM,
			"NOTIFY_MODULE" => "crm",
			//"NOTIFY_EVENT" => "callListCreated",
			"NOTIFY_EVENT" => "changeAssignedBy",
			"NOTIFY_TAG" => "CRM|CALL_LIST|" . ($activityFields['ID'] ?? 0),
			"NOTIFY_MESSAGE" => $notifyMessageCallback,
			"NOTIFY_MESSAGE_OUT" => $notifyMessageOutCallback,
			"PARAMS" => [
				'COMPONENT_ID' => 'CrmEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $notifySubjectCallback,
					'ENTITY' => [
						'TITLE' => htmlspecialcharsbx($subject),
						'HREF' => $url,
						'ENTITY_TYPE' => 'call',
						'CONTENT_TYPE' => 'title',
					],
				],
			],
		);

		\CIMNotify::Add($notification);
	}

	/**
	 * Returns update permission for a callList activity. Returns true if specified user has update permission for,
	 * at least one bound entity.
	 * @param array $activityFields Fields of the activity.
	 * @param int|null $userId Id of the user.
	 * @return bool
	 */
	public static function checkUpdatePermission(array $activityFields, $userId = null)
	{
		$callListId = $activityFields['ASSOCIATED_ENTITY_ID'];
		try
		{
			$callList = \Bitrix\Crm\CallList\CallList::createWithId(
				$callListId,
				true,
				static::getCallListLoadOptions($userId),
			);
		}
		catch (Main\SystemException $e)
		{
			return false;
		}

		$permission = \CCrmPerms::GetUserPermissions($userId);
		if(!is_array($callList->getItems()) || count($callList->getItems()) == 0)
			return true;

		foreach ($callList->getItems() as $callListItem)
		{
			if(\CCrmActivity::CheckReadPermission($callList->getEntityTypeId(), $callListItem->getElementId(), $permission))
			{
				return true;
			}
		}

		return false;
	}

	public static function checkReadPermission(array $activityFields, $userId = null)
	{
		if (!parent::checkReadPermission($activityFields, $userId))
		{
			return false;
		}
		$callListId = $activityFields['ASSOCIATED_ENTITY_ID'];
		if (self::$readVerdicts === null || !is_numeric($userId) || !is_numeric($callListId))
		{
			return self::canReadCallList($callListId, $userId);
		}

		return self::$readVerdicts[(int)$userId][(int)$callListId] ??= self::canReadCallList($callListId, $userId);
	}

	/**
	 * Takes at once the verdicts {@see self::checkReadPermission()} reaches about these call lists past the check of
	 * the base class, and keeps them until forgetReadVerdicts(), for a reader asking about many activities at once.
	 * The call list is decided as {@see self::canReadCallList()} decides it: its row is there, the user owns it by its
	 * author or by delegation, may read the items of its type, and at least one of its items is there and may be read.
	 *
	 * The rows are read by one query and the delegation by one
	 * ({@see \Bitrix\Crm\CallList\CallList::filterReadableByOwner()}), whatever the number of the call lists;
	 * whether the items are there, their categories and their permission attributes by a query each per type of an
	 * item and per turn of looking at the items.
	 *
	 * The items are read in portions, since one readable item decides a call list: the first items of every call
	 * list ({@see self::FIRST_ITEMS}), then the next ones ({@see self::ITEMS_BY_PORTIONS}) of the call lists left
	 * undecided that have more, and past the last portion the rest of them by their key and once
	 * ({@see self::decideByAllItems()}), until every call list is decided or out of items - a call list is
	 * refused only once all its items are looked at. When the first items decide every call list, which they do
	 * unless a user reads none of them, the items are read by one query per {@see self::ITEMS_READ_AT_ONCE} of
	 * them and looked at in two turns in their order ({@see self::FIRST_ITEMS_LOOKED_AT_ONCE}). No query reads more
	 * rows than that, and what a group of call lists past the first items preloaded is released before the next
	 * one is read, so neither a statement nor the memory grows with the items.
	 *
	 * On a database without window functions ({@see self::hasWindowFunctions()}) the items are read whole, in the
	 * order of their primary key and as many a query ({@see self::decideByAllItems()}).
	 *
	 * An item decides a call list only if the list of the items of its type lets the user of the hit read it
	 * ({@see self::conditionsOfLists()}), as the rule drops the others, and it is still looked at, as the rule looks
	 * at it. Past the very first items, and on a database without window functions from the start, the rows are
	 * asked for by the same condition, so a call list the user reads no item of is refused by a query that finds
	 * none, however long it is.
	 *
	 * The batch is stricter than the rule in one case and milder in none: the rule keeps an item its list dropped
	 * when the item has a phone ({@see \Bitrix\Crm\CallList\CallList::resolveItemFields()}), and the batch refuses
	 * it. That takes an item with a phone whose row of permission attributes is missing or stale, or a user asked
	 * about who is not the user of the hit; answering as the rule there would mean looking at every item again.
	 *
	 * The list of the items builds that condition for the user of the hit, whoever is asked about, and so does
	 * the batch: asked about another user, it lets through what the list lets the user of the hit read and looks
	 * at the items for the user asked about, as the rule does. A call list of a type whose list is not known here
	 * ({@see self::LISTS_OF_ITEMS}) is not decided: the rule decides it by itself.
	 *
	 * @param int[] $callListIds
	 * @return int[] the types of the items whose categories were preloaded: whoever preloads releases them
	 */
	public static function preloadReadVerdicts(array $callListIds, int $userId): array
	{
		self::$readVerdicts ??= [];
		$ids = [];
		foreach ($callListIds as $callListId)
		{
			$callListId = (int)$callListId;
			if ($callListId > 0 && !isset(self::$readVerdicts[$userId][$callListId]))
			{
				$ids[$callListId] = $callListId;
				self::$readVerdicts[$userId][$callListId] = false;
			}
		}
		if (empty($ids))
		{
			return [];
		}

		$rows = [];
		$cursor = CallListTable::getList([
			'select' => ['ID', 'CREATED_BY_ID', 'ENTITY_TYPE_ID'],
			'filter' => ['@ID' => array_values($ids)],
			// the queries that follow name the call lists in the order of these rows
			'order' => ['ID' => 'ASC'],
		]);
		while ($row = $cursor->fetch())
		{
			$rows[(int)$row['ID']] = $row;
		}

		$readableByOwner = [];
		foreach (\Bitrix\Crm\CallList\CallList::filterReadableByOwner($rows, $userId) as $callListId)
		{
			$entityTypeId = (int)($rows[$callListId]['ENTITY_TYPE_ID'] ?? 0);
			if (
				in_array($entityTypeId, self::TYPES_OF_ITEMS, true)
				&& (
					!isset(self::LISTS_OF_ITEMS[$entityTypeId])
					|| !Container::getInstance()->getFactory($entityTypeId)
				)
			)
			{
				// neither the condition of the list of the items nor a factory to read the items for the batch
				// with: the rule decides this call list one by one
				unset(self::$readVerdicts[$userId][$callListId]);

				continue;
			}
			$readableByOwner[] = $callListId;
		}

		$typesOfLists = self::typesOfListsWithReadableType($readableByOwner, $rows, $userId);
		$conditions = self::conditionsOfLists(array_unique($typesOfLists));
		// no item of such a type is read: its call lists stay refused
		$typesOfLists = array_diff($typesOfLists, array_keys($conditions, false, true));
		$readableItems = array_filter($conditions);
		if (empty($typesOfLists))
		{
			return [];
		}

		if (!self::hasWindowFunctions())
		{
			return array_keys(self::decideByAllItems($typesOfLists, $userId, $readableItems));
		}

		$preloadedTypes = [];
		$preloadedByPortion = [];
		$callListIds = array_keys($typesOfLists);
		// the very first items are read whatever the list of the items lets through: they decide a call list
		// unless the user reads few of its items, and only then is it worth asking for those let through alone
		$portions = empty($readableItems) ? [] : [[0, self::FIRST_ITEMS, []]];
		$after = 0;
		foreach (self::ITEMS_BY_PORTIONS as $upTo)
		{
			$portions[] = [$after, $upTo, $readableItems];
			$after = $upTo;
		}
		foreach ($portions as $number => [$after, $upTo, $letThrough])
		{
			if (empty($callListIds))
			{
				break;
			}
			$listsAtOnce = max(1, intdiv(self::ITEMS_READ_AT_ONCE, $upTo - $after));
			$undecided = [];
			if ($number > 0 && $after === 0)
			{
				// a type without a condition has had these very rows read by the portion before
				$undecided = array_filter(
					$callListIds,
					static fn(int $callListId): bool => !isset($readableItems[$typesOfLists[$callListId]]),
				);
				$callListIds = array_diff($callListIds, $undecided);
			}
			foreach (array_chunk($callListIds, $listsAtOnce) as $group)
			{
				$rows = self::itemRowsOfLists(self::rowsOfLists($group, $typesOfLists, $letThrough), $after, $upTo);
				if ($after === 0)
				{
					$preloadedTypes += self::decideByFirstItems($rows, $typesOfLists, $userId, $readableItems);
				}
				else
				{
					// what the group before preloaded is of no use any more: the memory holds one group at most
					self::releaseItems($preloadedByPortion);
					$preloadedByPortion = self::decideByItems(
						array_map(static fn(array $ids): array => array_combine($ids, $ids), $rows),
						$typesOfLists,
						$userId,
						$readableItems,
					);
					$preloadedTypes += $preloadedByPortion;
				}
				foreach ($rows as $callListId => $elementIds)
				{
					if (count($elementIds) >= $upTo - $after && !self::$readVerdicts[$userId][$callListId])
					{
						$undecided[] = $callListId;
					}
				}
			}
			// the queries name the call lists in the order of their ids
			sort($undecided);
			$callListIds = $undecided;
		}
		if (!empty($callListIds))
		{
			// every portion by position numbers the whole call list anew: a long one is read on by its key, once
			self::releaseItems($preloadedByPortion);
			$preloadedTypes += self::decideByAllItems(
				array_intersect_key($typesOfLists, array_flip($callListIds)),
				$userId,
				$readableItems,
			);
		}

		return array_keys($preloadedTypes);
	}

	/**
	 * Looks at the first items of the call lists in two turns in their order: the very first ones
	 * ({@see self::FIRST_ITEMS_LOOKED_AT_ONCE}), then the others of the call lists left undecided.
	 *
	 * @param array<int, int[]> $firstRows call list id => the elements of its first rows
	 * @param array<int, int> $typesOfLists call list id => the type of its items
	 * @param array<int, string> $readableItems the conditions of {@see self::conditionsOfLists()} that are queries
	 * @return array<int, int[]> as {@see self::decideByItems()} returns
	 */
	private static function decideByFirstItems(
		array $firstRows,
		array $typesOfLists,
		int $userId,
		array $readableItems,
	): array
	{
		$preloadedTypes = [];
		$looked = 0;
		foreach ([self::FIRST_ITEMS_LOOKED_AT_ONCE, self::FIRST_ITEMS] as $upTo)
		{
			$items = [];
			foreach ($firstRows as $callListId => $elementIds)
			{
				$elementIds = array_slice($elementIds, $looked, $upTo - $looked);
				if (!empty($elementIds) && !self::$readVerdicts[$userId][$callListId])
				{
					$items[$callListId] = array_combine($elementIds, $elementIds);
				}
			}
			$preloadedTypes += self::decideByItems($items, $typesOfLists, $userId, $readableItems);
			$looked = $upTo;
		}

		return $preloadedTypes;
	}

	/**
	 * Decides the call lists on a database without window functions, where no query reads a part of every call
	 * list, and the call lists longer than the portions by position: the rows of the items are read in the order
	 * of their primary key, {@see self::ITEMS_READ_AT_ONCE} of them a query, and looked at as they come, so a row
	 * is read once however long its call list is. A call list decided is left out of the queries that follow.
	 *
	 * @param array<int, int> $typesOfLists call list id => the type of its items
	 * @param array<int, string> $readableItems the conditions of {@see self::conditionsOfLists()} that are queries
	 * @return array<int, int[]> as {@see self::decideByItems()} returns
	 */
	private static function decideByAllItems(array $typesOfLists, int $userId, array $readableItems): array
	{
		$preloadedTypes = [];
		$preloadedByPortion = [];
		$last = null;
		do
		{
			$undecided = [];
			foreach ($typesOfLists as $callListId => $entityTypeId)
			{
				if (!self::$readVerdicts[$userId][$callListId])
				{
					$undecided[] = $callListId;
				}
			}
			if (empty($undecided))
			{
				break;
			}
			$filter = [self::rowsOfLists($undecided, $typesOfLists, $readableItems)];
			if ($last !== null)
			{
				$filter[] = [
					'LOGIC' => 'OR',
					'>LIST_ID' => $last['LIST_ID'],
					[
						'=LIST_ID' => $last['LIST_ID'],
						[
							'LOGIC' => 'OR',
							'>ENTITY_TYPE_ID' => $last['ENTITY_TYPE_ID'],
							['=ENTITY_TYPE_ID' => $last['ENTITY_TYPE_ID'], '>ELEMENT_ID' => $last['ELEMENT_ID']],
						],
					],
				];
			}
			$rows = CallListItemTable::getList([
				'select' => ['LIST_ID', 'ENTITY_TYPE_ID', 'ELEMENT_ID'],
				'filter' => $filter,
				'order' => ['LIST_ID' => 'ASC', 'ENTITY_TYPE_ID' => 'ASC', 'ELEMENT_ID' => 'ASC'],
				'limit' => self::ITEMS_READ_AT_ONCE,
			])->fetchAll();

			$itemsOfLists = [];
			foreach ($rows as $last)
			{
				$elementId = (int)$last['ELEMENT_ID'];
				if ($elementId > 0)
				{
					$itemsOfLists[(int)$last['LIST_ID']][$elementId] = $elementId;
				}
			}
			self::releaseItems($preloadedByPortion);
			$preloadedByPortion = self::decideByItems($itemsOfLists, $typesOfLists, $userId, $readableItems);
			$preloadedTypes += $preloadedByPortion;
		}
		while (count($rows) >= self::ITEMS_READ_AT_ONCE);

		return $preloadedTypes;
	}

	/**
	 * Drops the categories and the permission attributes looking at these items preloaded: the call lists they
	 * decided keep their verdicts, and the items are not looked at again.
	 *
	 * @param array<int, int[]> $itemIdsByType the type of the items => the items
	 */
	private static function releaseItems(array $itemIdsByType): void
	{
		foreach ($itemIdsByType as $entityTypeId => $itemIds)
		{
			if (empty($itemIds))
			{
				continue;
			}
			Container::getInstance()->getFactory($entityTypeId)?->releasePreloadedItemCategoryIds($itemIds);
			$controller = self::securityControllerOf($entityTypeId);
			foreach ($controller ? $itemIds : [] as $itemId)
			{
				$controller->clearPermissionAttributesCache($itemId);
			}
		}
	}

	private static function securityControllerOf(int $entityTypeId): ?SecurityController
	{
		$controller = SecurityManager::resolveController(
			(new PermissionEntityTypeHelper($entityTypeId))->getPermissionEntityTypeForCategory(0),
		);

		return $controller instanceof SecurityController ? $controller : null;
	}

	/**
	 * Whether the database of the items has window functions, which the query of the first items needs. MySQL has
	 * them since 8.0.2 and MariaDB since 10.2, while a product updated in place may still run on an older one.
	 */
	private static function hasWindowFunctions(): bool
	{
		if (self::$windowFunctions === null)
		{
			$connection = CallListItemTable::getEntity()->getConnection();
			self::$windowFunctions = true;
			if ($connection instanceof Main\DB\MysqlCommonConnection)
			{
				// the version the connection reports has lost the name of MariaDB, whose numbers mean another thing
				$resource = $connection->getResource();
				$serverInfo = $resource instanceof \mysqli ? $resource->server_info : $connection->getVersion()[0];
				self::$windowFunctions = self::windowFunctionsOf((string)$serverInfo);
			}
		}

		return self::$windowFunctions;
	}

	/**
	 * @param string $serverInfo what a MySQL server says of itself: '8.0.36', '10.4.12-MariaDB',
	 * '5.5.5-10.4.12-MariaDB'
	 */
	private static function windowFunctionsOf(string $serverInfo): bool
	{
		if (preg_match('/(\d+\.\d+(?:\.\d+)?)-MariaDB/i', $serverInfo, $match))
		{
			return version_compare($match[1], '10.2.0', '>=');
		}
		if (preg_match('/\d+\.\d+\.\d+/', $serverInfo, $match))
		{
			return version_compare($match[0], '8.0.2', '>=');
		}

		return false;
	}

	/**
	 * The items of each of these call lists that stand past the first `$after` ones and up to the `$upTo`-th one in
	 * the order of the primary key, read by one query. An item is a row here: fewer rows than that many means the
	 * call list has no more items.
	 *
	 * @param array<int|string, mixed> $rowsOfLists the filter of the rows, as {@see self::rowsOfLists()} returns
	 * @return array<int, int[]> call list id => the elements of its rows
	 */
	private static function itemRowsOfLists(array $rowsOfLists, int $after, int $upTo): array
	{
		// the ORM takes a window function for an aggregate and groups by the columns selected: the whole primary
		// key is selected, so the grouping merges nothing
		$numbered = CallListItemTable::query()
			->setSelect(['LIST_ID', 'ENTITY_TYPE_ID', 'ELEMENT_ID', 'POSITION_IN_LIST'])
			->registerRuntimeField(new ExpressionField(
				'POSITION_IN_LIST',
				'ROW_NUMBER() OVER (PARTITION BY %s ORDER BY %s, %s)',
				['LIST_ID', 'ENTITY_TYPE_ID', 'ELEMENT_ID'],
				['data_type' => 'integer'],
			))
			->setFilter($rowsOfLists)
			->where('ELEMENT_ID', '>', 0)
		;
		$query = (new Query(Entity::getInstanceByQuery($numbered)))
			->setCustomBaseTableAlias('crm_call_list_first_items')
			->setSelect(['LIST_ID', 'ELEMENT_ID'])
			->where('POSITION_IN_LIST', '<=', $upTo)
			->setOrder(['LIST_ID' => 'ASC', 'POSITION_IN_LIST' => 'ASC'])
		;
		if ($after > 0)
		{
			$query->where('POSITION_IN_LIST', '>', $after);
		}
		$cursor = $query->exec();

		$rows = [];
		while ($item = $cursor->fetch())
		{
			$rows[(int)$item['LIST_ID']][] = (int)$item['ELEMENT_ID'];
		}

		return $rows;
	}

	/**
	 * The condition of the rights the list of the items of each of these types puts into its query, as a query of
	 * the items it lets the user of the hit read: what
	 * {@see \Bitrix\Crm\CallList\CallList::resolveItemFields()} drops the items of a call list by. Built once per
	 * type and user of the hit as long as the verdicts are kept.
	 *
	 * @param int[] $entityTypeIds the types of {@see self::LISTS_OF_ITEMS}
	 * @return array<int, string|false> the type => the query; an empty one when the list lets every item through,
	 * and false when it lets none, which is how it answers a user without a right over the type
	 */
	private static function conditionsOfLists(array $entityTypeIds): array
	{
		$userOfTheHit = (int)Container::getInstance()->getContext()->getUserId();
		$conditions = [];
		foreach ($entityTypeIds as $entityTypeId)
		{
			$conditions[$entityTypeId] = self::$conditionsOfLists[$userOfTheHit][$entityTypeId]
				??= self::LISTS_OF_ITEMS[$entityTypeId]::BuildPermSql('', 'READ', ['RAW_QUERY' => true])
			;
		}

		return $conditions;
	}

	/**
	 * The filter of the rows of these call lists that name an item the user may read by the list of the items.
	 *
	 * @param int[] $callListIds
	 * @param array<int, int> $typesOfLists call list id => the type of its items
	 * @param array<int, string> $readableItems the conditions of {@see self::conditionsOfLists()} that are queries
	 * @return array<int|string, mixed>
	 */
	private static function rowsOfLists(array $callListIds, array $typesOfLists, array $readableItems): array
	{
		$listsByType = [];
		foreach ($callListIds as $callListId)
		{
			$listsByType[$typesOfLists[$callListId]][] = $callListId;
		}
		$filter = ['LOGIC' => 'OR'];
		foreach ($listsByType as $entityTypeId => $ids)
		{
			$filter[] = ['@LIST_ID' => $ids] + (
				isset($readableItems[$entityTypeId])
					? ['@ELEMENT_ID' => new SqlExpression($readableItems[$entityTypeId])]
					: []
			);
		}

		return $filter;
	}

	/**
	 * Lets the user read the call lists of these that have an item which is there, is let through by the list of
	 * the items of its type and may be read. Whether the items are there and let through, their categories and
	 * their permission attributes are read by a query each per type.
	 *
	 * @param array<int, array<int, int>> $itemsOfLists call list id => its items by their ids
	 * @param array<int, int> $typesOfLists call list id => the type of its items
	 * @param array<int, string> $readableItems the conditions of {@see self::conditionsOfLists()} that are queries
	 * @return array<int, int[]> the type of the items whose categories were preloaded => the items of it that
	 * were not preloaded before
	 */
	private static function decideByItems(
		array $itemsOfLists,
		array $typesOfLists,
		int $userId,
		array $readableItems,
	): array
	{
		$itemIdsByType = [];
		foreach ($itemsOfLists as $callListId => $itemIds)
		{
			$itemIdsByType[$typesOfLists[$callListId]] ??= [];
			$itemIdsByType[$typesOfLists[$callListId]] += $itemIds;
		}

		$existingItemIdsByType = [];
		$preloaded = [];
		foreach ($itemIdsByType as $entityTypeId => $itemIds)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			$controller = self::securityControllerOf($entityTypeId);
			// a type without categories preloads none: what was read before is told by the attributes kept
			$wasRead = $factory?->isCategoriesSupported()
				? $factory->isItemCategoryIdPreloaded(...)
				: static fn(int $itemId): bool => (bool)$controller?->hasPermissionAttributesCached($itemId);
			$notPreloaded = array_filter($itemIds, static fn(int $itemId): bool => !$wasRead($itemId));
			$existingItemIdsByType[$entityTypeId] = self::preloadExistingItems(
				$entityTypeId,
				$itemIds,
				$userId,
				$readableItems[$entityTypeId] ?? null,
			);
			$preloaded[$entityTypeId] = array_keys(
				array_intersect_key($notPreloaded, $existingItemIdsByType[$entityTypeId]),
			);
		}

		$userPermissions = Container::getInstance()->getUserPermissions($userId);
		$legacyPermissions = \CCrmPerms::GetUserPermissions($userId);
		foreach ($itemsOfLists as $callListId => $itemIds)
		{
			$entityTypeId = $typesOfLists[$callListId];
			foreach ($itemIds as $itemId)
			{
				if (
					isset($existingItemIdsByType[$entityTypeId][$itemId])
					&& $userPermissions->item()->canRead($entityTypeId, $itemId)
					&& \CCrmActivity::CheckReadPermission($entityTypeId, $itemId, $legacyPermissions)
				)
				{
					self::$readVerdicts[$userId][$callListId] = true;
					break;
				}
			}
		}

		return $preloaded;
	}

	/**
	 * The call lists of these that may still be read once their owner let the user through, by the type of their
	 * items: those of a type whose items the call list resolves and the user may read. The right on a type is asked
	 * once per type.
	 *
	 * @param int[] $callListIds the call lists the user may read by their owner
	 * @param array<int, array<string, mixed>> $rows the rows of the call lists by their ids
	 * @return array<int, int> call list id => the type of its items
	 */
	private static function typesOfListsWithReadableType(array $callListIds, array $rows, int $userId): array
	{
		$userPermissions = Container::getInstance()->getUserPermissions($userId);
		$isAdmin = $userPermissions->isAdmin();
		$canReadType = [];
		$types = [];
		foreach ($callListIds as $callListId)
		{
			$entityTypeId = (int)($rows[$callListId]['ENTITY_TYPE_ID'] ?? 0);
			if (!in_array($entityTypeId, self::TYPES_OF_ITEMS, true))
			{
				continue;
			}

			$canReadType[$entityTypeId] ??= $isAdmin || $userPermissions->entityType()->canReadItems($entityTypeId);
			if ($canReadType[$entityTypeId])
			{
				$types[(int)$callListId] = $entityTypeId;
			}
		}

		return $types;
	}

	/**
	 * Which of these items of one type are there and let through by the list of the items of the type, read by
	 * one query, and the categories and the permission attributes of those that are, read for all of them, so
	 * that checking an item reads nothing more.
	 *
	 * @param int[] $itemIds
	 * @param string|null $readableItems the query of the items the list of the items lets the user read, if it
	 * lets through not every one
	 * @return array<int, int> the items that are there and let through, by their ids
	 */
	private static function preloadExistingItems(
		int $entityTypeId,
		array $itemIds,
		int $userId,
		?string $readableItems,
	): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return [];
		}

		$existing = [];
		$cursor = $factory->getDataClass()::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => array_values($itemIds)]
				+ ($readableItems === null ? [] : [['@ID' => new SqlExpression($readableItems)]]),
		]);
		while ($row = $cursor->fetch())
		{
			$existing[(int)$row['ID']] = (int)$row['ID'];
		}
		if (!empty($existing))
		{
			$factory->preloadItemCategoryIds(array_values($existing));
			Container::getInstance()
				->getUserPermissions($userId)
				->item()
				->preloadPermissionAttributes($entityTypeId, array_values($existing))
			;
		}

		return $existing;
	}

	/**
	 * Stops keeping the verdicts about call lists: every question reads its call list anew.
	 */
	public static function forgetReadVerdicts(): void
	{
		self::$readVerdicts = null;
		self::$conditionsOfLists = [];
	}

	private static function canReadCallList($callListId, $userId): bool
	{
		try
		{
			$callList = \Bitrix\Crm\CallList\CallList::createWithId(
				$callListId,
				true,
				static::getCallListLoadOptions($userId),
			);
		}
		catch (Main\SystemException $e)
		{
			return false;
		}

		$permission = \CCrmPerms::GetUserPermissions($userId);
		if (!is_array($callList->getItems()) || count($callList->getItems()) === 0)
		{
			return false;
		}

		foreach ($callList->getItems() as $callListItem)
		{
			if(\CCrmActivity::CheckReadPermission($callList->getEntityTypeId(), $callListItem->getElementId(), $permission))
			{
				return true;
			}
		}

		return false;
	}

	private static function getCallListLoadOptions($userId): array
	{
		if ($userId === null)
		{
			return [];
		}

		return ['userId' => (int)$userId];
	}

	public static function canUseCalendarEvents($providerTypeId = null)
	{
		return true;
	}

	public static function transferOwnership($oldEntityTypeId, $oldEntityId, $newEntityTypeId, $newEntityId)
	{
		\Bitrix\Crm\CallList\CallList::transferOwnership($oldEntityTypeId, $oldEntityId, $newEntityTypeId, $newEntityId);
	}

	public static function deleteByOwner($entityTypeId, $entityId)
	{
		\Bitrix\Crm\CallList\CallList::deleteByOwner($entityTypeId, $entityId);
	}
}
