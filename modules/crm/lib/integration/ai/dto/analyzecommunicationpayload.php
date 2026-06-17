<?php

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Caster\CollectionCaster;
use Bitrix\Crm\Dto\Caster\ObjectCaster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AnalyzeCommunicationPayload extends Dto
{
	/** @var AnalyzeCommunicationActionPayload[] */
	public array $actions = [];
	public bool $isClient = false;
	public ?string $reasonIfIsClientFalse = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'actions' => new CollectionCaster(new ObjectCaster(AnalyzeCommunicationActionPayload::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'isClient'),
			new ObjectCollectionField($this, 'actions'),
			new class($this) extends Validator
			{
				public function validate(array $fields): Result
				{
					$result = new Result();
					if ($fields['isClient'] === false)
					{
						if (empty($fields['reasonIfIsClientFalse']))
						{
							$result->addError(new Error('reasonIfIsClientFalse must be set if isClient is false'));
						}

						if (!empty($fields['actions']))
						{
							$result->addError(new Error('actions must be empty if isClient is false'));
						}
					}

					return $result;
				}
			},
		];
	}
}
