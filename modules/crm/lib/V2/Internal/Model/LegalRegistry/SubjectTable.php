<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\EntityError;

final class SubjectTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_legal_subject';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\StringField('SUBJECT_UID'))
				->configureRequired()
				->configureBinary()
				->addValidator(static fn(mixed $value): bool|string => is_string($value) && strlen($value) >= 1 && strlen($value) <= 64
						? true
						: 'Invalid identity key size.',
				),
			(new Fields\IntegerField('CANONICAL_ID'))->configureNullable(),
			(new Fields\IntegerField('REVISION'))->configureRequired(),
		];
	}

	public static function checkFields(Result $result, mixed $primary, array $data): void
	{
		parent::checkFields($result, $primary, $data);
		if (!empty($primary) && array_key_exists('SUBJECT_UID', $data))
		{
			$row = self::getByPrimary($primary, ['select' => ['SUBJECT_UID']])->fetch();
			if ($row && $row['SUBJECT_UID'] !== $data['SUBJECT_UID'])
			{
				$result->addError(new EntityError('Subject UID is immutable.', 'INVALID_STATE'));
			}
		}
	}
}
