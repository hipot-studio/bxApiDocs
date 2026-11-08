<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

final class SourceTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_legal_source';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('FORM_ID'))->configureRequired(),
			(new Fields\StringField('NAME_SNAPSHOT'))
				->configureRequired()
				->addValidator(new Fields\Validators\LengthValidator(1, 255)),
			(new Fields\EnumField('STATE'))->configureRequired()->configureValues(['active', 'inactive', 'deleted']),
			(new Fields\IntegerField('REVISION'))->configureRequired(),
		];
	}
}
