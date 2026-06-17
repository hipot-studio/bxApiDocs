<?php

namespace Bitrix\BIConnector\Integration\Superset\Model;

use Bitrix\BIConnector\Access\Service\RolePermissionService;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardChatTable;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardViewTable;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoTable;
use Bitrix\BIConnector\Internal\Integration\Im\DashboardDiscussionChatAccessSyncScheduler;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\AppTable;

/**
 * Class DashboardTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> EXTERNAL_ID int
 * <li> STATUS char(1)
 * <li> DATE_FILTER_START date mandatory
 * <li> DATE_FILTER_END date mandatory
 * <li> TYPE enum mandatory
 * <li> APP_ID string(128)
 * <li> SOURCE_ID int
 * <li> LANG char(2)
 * </ul>
 *
 * @package Bitrix\BIConnector
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetDashboard_Query query()
 * @method static EO_SupersetDashboard_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetDashboard_Result getById($id)
 * @method static EO_SupersetDashboard_Result getList(array $parameters = [])
 * @method static EO_SupersetDashboard_Entity getEntity()
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardCollection createCollection()
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardCollection wakeUpCollection($rows)
 */

final class SupersetDashboardTable extends DataManager
{
	public const DASHBOARD_TYPE_SYSTEM = 'SYSTEM';
	public const DASHBOARD_TYPE_CUSTOM = 'CUSTOM';
	public const DASHBOARD_TYPE_MARKET = 'MARKET';

	public const DASHBOARD_STATUS_LOAD = 'L';
	public const DASHBOARD_STATUS_READY = 'R';
	public const DASHBOARD_STATUS_DRAFT = 'D';
	public const DASHBOARD_STATUS_FAILED = 'F';
	public const DASHBOARD_STATUS_NOT_INSTALLED = 'N';

	public static function getObjectClass()
	{
		return SupersetDashboard::class;
	}

	public static function getCollectionClass()
	{
		return SupersetDashboardCollection::class;
	}

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_biconnector_superset_dashboard';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary(),
			(new Fields\IntegerField('EXTERNAL_ID')),

			(new Fields\EnumField('STATUS'))
				->configureRequired()
				->configureValues([
					self::DASHBOARD_STATUS_LOAD,
					self::DASHBOARD_STATUS_READY,
					self::DASHBOARD_STATUS_DRAFT,
					self::DASHBOARD_STATUS_FAILED,
					self::DASHBOARD_STATUS_NOT_INSTALLED,
				])
				->configureDefaultValue(self::DASHBOARD_STATUS_READY)
			,

			(new Fields\StringField('TITLE'))
				->configureSize(128)
			,

			(new Fields\DateField('DATE_FILTER_START'))
				->configureNullable()
			,

			(new Fields\DateField('DATE_FILTER_END'))
				->configureNullable()
			,

			(new Fields\EnumField('TYPE'))
				->configureRequired()
				->configureValues([
					self::DASHBOARD_TYPE_SYSTEM,
					self::DASHBOARD_TYPE_CUSTOM,
					self::DASHBOARD_TYPE_MARKET,
				])
				->configureDefaultValue(self::DASHBOARD_TYPE_CUSTOM)
			,

			(new Fields\StringField('FILTER_PERIOD')),

			(new Fields\StringField('APP_ID'))
				->configureSize(128)
			,

			(new ReferenceField(
				'APP',
				AppTable::class,
				Join::on('this.APP_ID', 'ref.CODE')
			))->configureJoinType(Join::TYPE_LEFT),
			(new Fields\IntegerField('SOURCE_ID')),

			(new ReferenceField(
				'SOURCE',
				self::class,
				Join::on('this.SOURCE_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_LEFT),

			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime()),

			(new Fields\DatetimeField('DATE_MODIFY'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime()),

			(new Fields\IntegerField('CREATED_BY_ID'))
				->configureNullable(),

			/** @deprecated  */
			(new Fields\IntegerField('OWNER_ID'))
				->configureNullable(),

			(new Fields\StringField('LANG'))
				->configureSize(2)
				->configureNullable()
			,

			(new Fields\Relations\ManyToMany('TAGS', SupersetTagTable::class))
				->configureMediatorTableName('b_biconnector_superset_dashboard_tag')
				->configureLocalPrimary('ID', 'DASHBOARD_ID')
				->configureRemotePrimary('ID', 'TAG_ID')
				->configureCascadeDeletePolicy(Fields\Relations\CascadePolicy::FOLLOW)
			,

			(new Fields\Relations\OneToMany(
				'SCOPE',
				SupersetScopeTable::class,
				'DASHBOARD',
			))
				->configureJoinType(Join::TYPE_LEFT)
				->configureCascadeDeletePolicy(Fields\Relations\CascadePolicy::FOLLOW)
			,
			(new Fields\EnumField('INCLUDE_LAST_FILTER_DATE'))
				->configureValues(['N', 'Y'])
				->configureNullable()
			,

			(new Fields\Relations\OneToMany(
				'URL_PARAMS',
				SupersetDashboardUrlParameterTable::class,
				'DASHBOARD',
			))
				->configureJoinType(Join::TYPE_LEFT)
				->configureCascadeDeletePolicy(Fields\Relations\CascadePolicy::FOLLOW)
			,

			(new Fields\Relations\ManyToMany(
				'GROUPS',
				SupersetDashboardGroupTable::class,
			))
				->configureMediatorTableName('b_biconnector_superset_dashboard_group_binding')
				->configureLocalPrimary('ID', 'DASHBOARD_ID')
				->configureRemotePrimary('ID', 'GROUP_ID')
			,

			(new Fields\Relations\OneToMany(
				'SHARES',
				SupersetDashboardShareTable::class,
				'DASHBOARD',
			))
				->configureJoinType(Join::TYPE_LEFT)
				->configureCascadeDeletePolicy(Fields\Relations\CascadePolicy::FOLLOW)
			,
		];
	}

	public static function onAfterDelete(Event $event): void
	{
		$dashboardId = (int)$event->getParameters()['primary']['ID'];
		$fileCleanupService = ServiceLocator::getInstance()->get('biconnector.service.dashboardInfo.fileCleanup');
		$imageIdsToDelete = $fileCleanupService->collectImageIdsByDashboardId($dashboardId);
		$service = new RolePermissionService();
		$service->deletePermissionsByDashboard($dashboardId);

		$topMenuDashboardsOptions = \CUserOptions::getList(
			['ID' => 'ASC'],
			[
				'CATEGORY' => 'biconnector',
				'NAME' => 'top_menu_dashboards',
			]
		);
		while ($row = $topMenuDashboardsOptions->fetch())
		{
			$topMenuDashboards = unserialize($row['VALUE'], ['allowed_classes' => false]);
			if (
				is_array($topMenuDashboards)
				&& in_array($dashboardId, $topMenuDashboards, true)
			)
			{
				$topMenuDashboards = array_filter($topMenuDashboards, static fn ($item) => $item !== $dashboardId);
				\CUserOptions::setOption(
					category: 'biconnector',
					name: 'top_menu_dashboards',
					value: $topMenuDashboards,
					user_id: $row['USER_ID'],
				);
			}
		}

		$pinnedDashboardsOptions = \CUserOptions::getList(
			['ID' => 'ASC'],
			[
				'CATEGORY' => 'biconnector',
				'NAME' => 'grid_pinned_dashboards',
			]
		);
		while ($row = $pinnedDashboardsOptions->fetch())
		{
			$pinnedDashboards = unserialize($row['VALUE'], ['allowed_classes' => false]);
			if (
				is_array($pinnedDashboards)
				&& in_array($dashboardId, $pinnedDashboards, true)
			)
			{
				$pinnedDashboards = array_filter($pinnedDashboards, static fn ($item) => $item !== $dashboardId);
				\CUserOptions::setOption(
					category: 'biconnector',
					name: 'grid_pinned_dashboards',
					value: $pinnedDashboards,
					user_id: $row['USER_ID'],
				);
			}
		}

		$scopes = SupersetScopeTable::getList([
				'filter' => ['=DASHBOARD_ID' => $dashboardId],
			])
			->fetchCollection()
		;
		foreach ($scopes as $scopeBinding)
		{
			$deleteResult = $scopeBinding->delete();
			if (!$deleteResult->isSuccess())
			{
				Logger::logErrors($deleteResult->getErrors(), ['Deleting scopes of dashboard ' . $dashboardId]);
			}
		}

		$tags = SupersetDashboardTagTable::getList([
				'filter' => ['=DASHBOARD_ID' => $dashboardId],
			])
			->fetchCollection()
		;
		foreach ($tags as $tagBinding)
		{
			$deleteResult = $tagBinding->delete();
			if (!$deleteResult->isSuccess())
			{
				Logger::logErrors($deleteResult->getErrors(), ['Deleting tags of dashboard ' . $dashboardId]);
			}
		}

		$urlParams = SupersetDashboardUrlParameterTable::getList([
				'filter' => ['=DASHBOARD_ID' => $dashboardId],
			])
			->fetchCollection()
		;
		foreach ($urlParams as $paramBinding)
		{
			$deleteResult = $paramBinding->delete();
			if (!$deleteResult->isSuccess())
			{
				Logger::logErrors($deleteResult->getErrors(), ['Deleting url params of dashboard ' . $dashboardId]);
			}
		}

		SupersetDashboardGroupBindingTable::deleteByFilter(['=DASHBOARD_ID' => $dashboardId]);

		SupersetDashboardShareTable::deleteByFilter(['=DASHBOARD_ID' => $dashboardId]);
		SupersetDashboardViewTable::deleteByFilter(['=DASHBOARD_ID' => $dashboardId]);
		SupersetDashboardInfoTable::deleteByFilter(['=DASHBOARD_ID' => $dashboardId]);
		SupersetDashboardChatTable::deleteByFilter(['=DASHBOARD_ID' => $dashboardId]);
		$fileCleanupService->deleteFiles($imageIdsToDelete);
	}

	public static function onAfterAdd(Event $event)
	{
		parent::onAfterAdd($event);

		$dashboardId = (int)($event->getParameter('id') ?? 0);
		$fields = $event->getParameters()['fields'] ?? [];
		$fields = is_array($fields) ? $fields : [];

		$dashboardType = $fields['TYPE'] ?? self::DASHBOARD_TYPE_CUSTOM;
		if ($dashboardId > 0 && $dashboardType === self::DASHBOARD_TYPE_CUSTOM)
		{
			$dashboardInfoFields = [
				'DASHBOARD_ID' => $dashboardId,
			];

			$dashboardStatus = $fields['STATUS'] ?? self::DASHBOARD_STATUS_READY;
			if ($dashboardStatus === self::DASHBOARD_STATUS_READY)
			{
				$publishedById = (int)($fields['CREATED_BY_ID'] ?? CurrentUser::get()->getId());
				$dashboardInfoFields['PUBLISHED_BY_ID'] = $publishedById > 0 ? $publishedById : null;
				$dashboardInfoFields['PUBLISHED_DATE'] = $fields['DATE_CREATE'] ?? new DateTime();
			}

			SupersetDashboardInfoTable::add($dashboardInfoFields);
		}
	}

	public static function onAfterUpdate(Event $event)
	{
		parent::onAfterUpdate($event);

		$id = (int)($event->getParameter('primary')['ID'] ?? 0);
		$fields = $event->getParameters()['fields'] ?? [];

		if ($id <= 0)
		{
			return;
		}

		$dashboard = static::getRow([
			'select' => ['TYPE'],
			'filter' => ['=ID' => $id],
		]);
		$dashboardType = $dashboard['TYPE'] ?? null;
		if ($dashboardType !== self::DASHBOARD_TYPE_CUSTOM)
		{
			return;
		}

		$dashboardInfo = SupersetDashboardInfoTable::getRow([
			'select' => ['ID'],
			'filter' => ['=DASHBOARD_ID' => $id],
		]);

		$dashboardInfoId = (int)($dashboardInfo['ID'] ?? 0);
		if ($dashboardInfoId <= 0)
		{
			$addResult = SupersetDashboardInfoTable::add([
				'DASHBOARD_ID' => $id,
			]);
			if (!$addResult->isSuccess())
			{
				return;
			}

			$dashboardInfoId = (int)$addResult->getId();
		}

		$currentUserId = (int)($fields['MODIFIED_BY_ID'] ?? CurrentUser::get()->getId());
		if ($currentUserId <= 0)
		{
			$currentUserId = null;
		}

		$updateFields = [
			'UPDATED_BY_ID' => $currentUserId,
			'UPDATED_DATE' => $fields['DATE_MODIFY'] ?? new DateTime(),
		];

		$isStatusChangedToReady = isset($fields['STATUS']) && $fields['STATUS'] === self::DASHBOARD_STATUS_READY;
		$isStatusChangedToDraft = isset($fields['STATUS']) && $fields['STATUS'] === self::DASHBOARD_STATUS_DRAFT;
		if ($isStatusChangedToReady)
		{
			$updateFields['PUBLISHED_BY_ID'] = $currentUserId;
			$updateFields['PUBLISHED_DATE'] = $fields['DATE_MODIFY'] ?? new DateTime();
		}
		elseif ($isStatusChangedToDraft)
		{
			$updateFields['PUBLISHED_BY_ID'] = null;
			$updateFields['PUBLISHED_DATE'] = null;
		}

		SupersetDashboardInfoTable::update($dashboardInfoId, $updateFields);

		if (array_key_exists('STATUS', $fields))
		{
			(new DashboardDiscussionChatAccessSyncScheduler())->scheduleDashboardIds([$id]);
		}
	}
}
