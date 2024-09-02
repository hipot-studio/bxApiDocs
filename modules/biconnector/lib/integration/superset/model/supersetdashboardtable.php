<?php

namespace Bitrix\BIConnector\Integration\Superset\Model;

use Bitrix\BIConnector\Access\Service\RolePermissionService;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
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
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard_Collection createCollection()
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard_Collection wakeUpCollection($rows)
 */

final class SupersetDashboardTable extends DataManager
{
	public const DASHBOARD_TYPE_SYSTEM = 'SYSTEM';
	public const DASHBOARD_TYPE_CUSTOM = 'CUSTOM';
	public const DASHBOARD_TYPE_MARKET = 'MARKET';

	public const DASHBOARD_STATUS_LOAD = 'L';
	public const DASHBOARD_STATUS_READY = 'R';
	public const DASHBOARD_STATUS_FAILED = 'F';

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
					self::DASHBOARD_STATUS_FAILED,
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
				->configureDefaultValue(self::DASHBOARD_TYPE_CUSTOM),

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

			(new Fields\IntegerField('OWNER_ID'))
				->configureNullable(),

			(new Fields\Relations\ManyToMany('TAGS', SupersetTagTable::class))
				->configureMediatorTableName('b_biconnector_superset_dashboard_tag')
				->configureLocalPrimary('ID', 'DASHBOARD_ID')
				->configureRemotePrimary('ID', 'TAG_ID'),

			(new Fields\Relations\OneToMany(
				'SCOPE',
				SupersetScopeTable::class,
				'DASHBOARD',
			))
				->configureJoinType(Join::TYPE_LEFT)
			,
		];
	}

	public static function onAfterDelete(Event $event): void
	{
		$dashboardId = (int)$event->getParameters()['primary']['ID'];
		$service = new RolePermissionService();
		$service->deletePermissionsByDashboard($dashboardId);

		$tagBindings = SupersetDashboardTagTable::getList([
			'filter' => ['=DASHBOARD_ID' => $dashboardId]
		])
			->fetchCollection()
		;

		foreach ($tagBindings as $tagBinding)
		{
			$tagDeleteResult = $tagBinding->delete();
			if (!$tagDeleteResult->isSuccess())
			{
				Logger::logErrors($tagDeleteResult->getErrors(), ['Deleting tags of dashboard ' . $dashboardId]);
			}
		}

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

		$scopeBindings = SupersetScopeTable::getList([
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		])
			->fetchCollection()
		;
		foreach ($scopeBindings as $scopeBinding)
		{
			$scopeDeleteResult = $scopeBinding->delete();
			if (!$scopeDeleteResult->isSuccess())
			{
				Logger::logErrors($scopeDeleteResult->getErrors(), ['Deleting scopes of dashboard ' . $dashboardId]);
			}
		}
	}
}
