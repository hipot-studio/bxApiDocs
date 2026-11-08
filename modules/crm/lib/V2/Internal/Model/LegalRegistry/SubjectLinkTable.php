<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

final class SubjectLinkTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_legal_subject_link';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('SUBJECT_ID'))->configureRequired(),
			(new Fields\IntegerField('ENTITY_TYPE_ID'))->configureRequired(),
			(new Fields\IntegerField('ENTITY_ID'))->configureRequired(),
			(new Fields\BooleanField('IS_CURRENT'))->configureRequired()->configureValues('N', 'Y'),
		];
	}
}
