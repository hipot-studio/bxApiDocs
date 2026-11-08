<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\Balance;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Vibecodeconnector\Internal\Entity\Balance\TopUpRequestStatus;

final class TopUpRequestTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_top_up_request';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('USER_ID'))->configureRequired(),
			(new EnumField('STATUS'))
				->configureValues(TopUpRequestStatus::values())
				->configureRequired(),
		];
	}
}
