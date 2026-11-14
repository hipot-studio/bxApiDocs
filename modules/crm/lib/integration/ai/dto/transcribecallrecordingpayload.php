<?php

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class TranscribeCallRecordingPayload extends Dto
{
	public string $transcription;
	/** @var list<array{startTime: string, endTime: string, text: string, speaker: ?string, trackId: ?string}>|null */
	public ?array $segments = null;
	public ?string $speakerSource = null;
	public ?int $transcriptVersion = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'segments' => new Caster\RawArrayCaster(),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'transcription'),
			new NotEmptyField($this, 'transcription'),
		];
	}
}
