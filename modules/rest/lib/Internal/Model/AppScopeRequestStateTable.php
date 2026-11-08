<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestState;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestStatus;

/**
 * Class AppScopeRequestStateTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AppScopeRequestState_Query query()
 * @method static EO_AppScopeRequestState_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AppScopeRequestState_Result getById($id)
 * @method static EO_AppScopeRequestState_Result getList(array $parameters = [])
 * @method static EO_AppScopeRequestState_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequestState createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequestState_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequestState wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppScopeRequestState_Collection wakeUpCollection($rows)
 */
class AppScopeRequestStateTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_rest_app_scope_request_state';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('REQUEST_ID'))
				->configureRequired(),

			(new EnumField('STATUS'))
				->configureRequired()
				->configureValues(array_column(AppScopeRequestStatus::cases(), 'value'))
				->configureDefaultValue(AppScopeRequestStatus::Pending->value),

			(new TextField('COMMENT'))
				->configureNullable(),

			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValueNow(),

			(new Reference(
				'REQUEST',
				AppScopeRequestTable::class,
				Join::on('this.REQUEST_ID', 'ref.ID'),
			)),
		];
	}
}
