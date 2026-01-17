<?php

namespace Bitrix\Crm\Security\Notification;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class EntityPermsNotificationTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_crm_entity_perms_notification';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('AUTOMATED_SOLUTION_ID'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new IntegerField('ENTITY_TYPE_ID'))
				->configureRequired()
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new StringField('PERM_TYPE'))
				->configureRequired()
				->configureSize(20)
			,
		];
	}

	public static function deleteByAutomatedSolutionId(int $automatedSolutionId): void
	{
		if ($automatedSolutionId <= 0)
		{
			throw new ArgumentException('Must be greater than zero.', 'automatedSolutionId');
		}

		self::deleteByFilter([
			'=AUTOMATED_SOLUTION_ID' => $automatedSolutionId,
		]);
	}
}
