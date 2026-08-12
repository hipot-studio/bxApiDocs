<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Outcome;

use Bitrix\Call\DTO\FollowUp\NullCompactArrayTrait;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

class TranscriptionDto extends Dto
{
	use NullCompactArrayTrait;

	#[Description('Detected transcription language as BCP-47 / ISO 639-1 code (e.g. "ru", "en"). Null when the language could not be determined.')]
	public ?string $language = null;

	/** @var TranscriptionSegmentDto[]|null */
	#[Description('Time-ordered transcription segments. Each segment is one continuous utterance by a single speaker.')]
	#[ElementType(TranscriptionSegmentDto::class)]
	public ?array $segments = null;
}
