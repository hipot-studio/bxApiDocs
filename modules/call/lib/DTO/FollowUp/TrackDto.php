<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

class TrackDto extends Dto
{
	use NullCompactArrayTrait;

	#[Description('Track identifier (b_call_track.ID).')]
	public ?int $trackId = null;

	#[Description('Track type / classification (e.g. mixed audio, video, single-speaker). Maps to Track::getType().')]
	public ?string $type = null;

	#[Description('Underlying b_file.ID of the stored media file.')]
	public ?int $fileId = null;

	#[Description('Disk file id (b_disk_file.ID) when the track is registered in the Disk module.')]
	public ?int $diskFileId = null;

	#[Description('Track duration in seconds.')]
	public ?int $duration = null;

	#[Description('Track file size in bytes.')]
	public ?int $fileSize = null;

	#[Description('Original file name as stored on Disk.')]
	public ?string $fileName = null;

	#[Description('MIME type of the track file (e.g. audio/wav, video/mp4).')]
	public ?string $mimeType = null;

	#[Description('Bitrix24 call identifier the track belongs to (b_call.ID).')]
	public ?int $callId = null;

	#[Description('Relative URL to the track file (path under the portal). Useful for embedding into existing portal UI.')]
	public ?string $relUrl = null;

	#[Description('Absolute URL to the track file with portal scheme/host applied — ready for direct download or playback.')]
	public ?string $url = null;

	#[Description('ISO 8601 UTC timestamp of when the track was registered.')]
	public ?string $dateCreate = null;
}
