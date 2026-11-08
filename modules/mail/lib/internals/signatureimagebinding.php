<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

class SignatureImageBindingTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_mail_signature_image_binding';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('SIGNATURE_ID'))
				->configurePrimary()
			,
			(new IntegerField('IMAGE_ID'))
				->configurePrimary()
			,
		];
	}
}
