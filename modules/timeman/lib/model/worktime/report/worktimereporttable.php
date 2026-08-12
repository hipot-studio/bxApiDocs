<?php
namespace Bitrix\Timeman\Model\Worktime\Report;

use Bitrix\Main;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordTable;
use Bitrix\Timeman\Service\DependencyManager;

/**
 * Class WorktimeReportTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorktimeReport_Query query()
 * @method static EO_WorktimeReport_Result getByPrimary($primary, array $parameters = array())
 * @method static EO_WorktimeReport_Result getById($id)
 * @method static EO_WorktimeReport_Result getList(array $parameters = array())
 * @method static EO_WorktimeReport_Entity getEntity()
 * @method static \Bitrix\Timeman\Model\Worktime\Report\WorktimeReport createObject($setDefaultValues = true)
 * @method static \Bitrix\Timeman\Model\Worktime\Report\EO_WorktimeReport_Collection createCollection()
 * @method static \Bitrix\Timeman\Model\Worktime\Report\WorktimeReport wakeUpObject($row)
 * @method static \Bitrix\Timeman\Model\Worktime\Report\EO_WorktimeReport_Collection wakeUpCollection($rows)
 */
class WorktimeReportTable extends Main\ORM\Data\DataManager
{
	public const REPORT_TYPE_ERR_OPEN = 'ERR_OPEN';
	public const REPORT_TYPE_ERR_CLOSE = 'ERR_CLOSE';
	public const REPORT_TYPE_ERR_DURATION = 'ERR_DURATION';
	public const REPORT_TYPE_REPORT_OPEN = 'REPORT_OPEN';
	public const REPORT_TYPE_REPORT_CLOSE = 'REPORT_CLOSE';
	public const REPORT_TYPE_REPORT_REOPEN = 'REOPEN';
	public const REPORT_TYPE_REPORT_DURATION = 'REPORT_DURATION';
	public const REPORT_TYPE_RECORD_REPORT = 'REPORT';
	public const REPORT_TYPE_RECORD_AI_REPORT = 'AI_REPORT';
	public const REPORT_TYPE_RECORD_ROBOT_REPORT = 'ROBOT_REPORT';
	public const REPORT_TYPE_AI_DAY_PLAN = 'AI_DAY_PLAN';
	public const REPORT_TYPE_ROBOT_DAY_PLAN = 'ROBOT_DAY_PLAN';

	public static function getObjectClass()
	{
		return WorktimeReport::class;
	}

	public static function getTableName()
	{
		return 'b_timeman_reports';
	}

	public static function onBeforeUpdate(Event $event)
	{
		$result = new EventResult;
		$data = $event->getParameter('fields');

		DependencyManager::getInstance()->getWorktimeEventsManager()
			->sendModuleEventsOnBeforeReportUpdate($data, $result);

		return $result;
	}

	public static function onAfterUpdate(Event $event)
	{
		$manager = DependencyManager::getInstance()->getWorktimeEventsManager();
		$result = new EventResult;

		$manager->sendModuleEventsOnAfterReportUpdate(
			$manager->extractIdFromEvent($event),
			$event->getParameter('fields')
		);

		return $result;
	}

	public static function onBeforeAdd(Event $event)
	{
		$result = new EventResult;
		$data = $event->getParameter('fields');

		DependencyManager::getInstance()->getWorktimeEventsManager()
			->sendModuleEventsOnBeforeReportAdd($data, $result);

		return $result;
	}

	public static function onAfterAdd(Event $event)
	{
		$result = new EventResult;
		DependencyManager::getInstance()->getWorktimeEventsManager()
			->sendModuleEventsOnAfterReportAdd($event->getParameter('fields'));

		return $result;
	}

	public static function getMap()
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new Fields\DatetimeField('TIMESTAMP_X'))
				->configureDefaultValue(function () {
					return new \Bitrix\Main\Type\DateTime();
				})
			,
			(new Fields\IntegerField('ENTRY_ID'))
			,
			(new Fields\IntegerField('USER_ID'))
			,
			(new Fields\BooleanField('ACTIVE'))
				->configureValues('N', 'Y')
			,
			(new Fields\TextField('REPORT_TYPE'))
			,
			(new Fields\TextField('REPORT'))
			,
			# relations
			(new Fields\Relations\Reference(
				'RECORD',
				WorktimeRecordTable::class,
				Join::on('this.ENTRY_ID', 'ref.ID')
			))
				->configureJoinType('INNER')
			,
		];
	}
}