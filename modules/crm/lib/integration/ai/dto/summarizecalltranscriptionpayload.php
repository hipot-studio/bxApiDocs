<?php

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class SummarizeCallTranscriptionPayload extends Dto
{
	public string $summary = '';
	public ?SummarizeCallTranscriptionData $data = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'summary'),
			new NotEmptyField($this, 'summary'),
		];
	}

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'data' => (new Caster\ObjectCaster(SummarizeCallTranscriptionData::class))->nullable(),
			default => null,
		};
	}
}
