<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\JsonField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class AppScopeRequestTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AppScopeRequest_Query query()
 * @method static EO_AppScopeRequest_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AppScopeRequest_Result getById($id)
 * @method static EO_AppScopeRequest_Result getList(array $parameters = [])
 * @method static EO_AppScopeRequest_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequest createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequest_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequest wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequest_Collection wakeUpCollection($rows)
 */
class AppScopeRequestTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_rest_app_scope_request';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('APP_ID'))
				->configureRequired(),

			(new JsonField('SCOPE'))
				->configureRequired(),

			(new IntegerField('STATE_ID'))
				->configureNullable(),

			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValueNow(),

			(new Reference(
				'APP',
				AppTable::class,
				Join::on('this.APP_ID', 'ref.ID'),
			)),

			(new Reference(
				'CURRENT_STATE',
				AppScopeRequestStateTable::class,
				Join::on('this.STATE_ID', 'ref.ID'),
			)),
		];
	}

	public static function onAfterDelete(Main\ORM\Event $event)
	{
		if (!$event->getParameter('id'))
		{
			return;
		}

		$id = $event->getParameter('id');

		$states = AppScopeRequestStateTable::query()
			->setSelect(['ID'])
			->where('REQUEST_ID', $id)
			->fetchAll();

		foreach ($states as $stateRow)
		{
			AppScopeRequestStateTable::delete($stateRow['ID']);
		}
	}
}
