<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

class EmbeddingAccessTable extends DataManager
{
	use AddInsertIgnoreTrait;
	use DeleteByFilterTrait;

	public const ACCESS_CODE_MAX_LENGTH = 100;

	public static function getTableName(): string
	{
		return 'b_rest_embedding_access';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('EMBEDDING_ID'))
				->configureRequired(),
			(new StringField('ACCESS_CODE'))
				->configureRequired()
				->addValidator(new LengthValidator(1, self::ACCESS_CODE_MAX_LENGTH)),
		];
	}
}
