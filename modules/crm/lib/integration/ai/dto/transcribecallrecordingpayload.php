<?php

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class TranscribeCallRecordingPayload extends Dto
{
	public string $transcription;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'transcription'),
			new NotEmptyField($this, 'transcription'),
		];
	}
}
