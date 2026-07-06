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
