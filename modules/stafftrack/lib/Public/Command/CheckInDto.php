<?php

namespace Bitrix\StaffTrack\Public\Command;

use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internals\AbstractDto;
use Bitrix\StaffTrack\Internals\Attribute\Enum;
use Bitrix\StaffTrack\Internals\Attribute\MaxLength;
use Bitrix\StaffTrack\Internals\Attribute\Min;
use Bitrix\StaffTrack\Internals\Attribute\NotEmpty;
use Bitrix\StaffTrack\Internals\Attribute\Nullable;
use Bitrix\StaffTrack\Internals\Attribute\Primary;
use Bitrix\StaffTrack\Internals\Attribute\Range;

/**
 * @method self setId(int $id)
 * @method self setUserId(int $userId)
 * @method self setDateCreate(DateTime $dateCreate)
 * @method self setEntityType(int $entityType)
 * @method self setDescription(string $description)
 * @method self setMessageIds(int $messageId)
 * @method self setLatitude(float $latitude)
 * @method self setLongitude(float $longitude)
 * @method self setUserTimezone(int $userTimezone)
 * @method self setAddress(string $address)
 * @method self setFileIds(array $fileIds)
 */
final class CheckInDto extends AbstractDto
{
	protected function setValue(\ReflectionProperty $property, mixed $value): void
	{
		if ($property->getName() === 'dateCreate' && is_numeric($value) && $value > 0)
		{
			$property->setValue($this, DateTime::createFromTimestamp((int)$value));

			return;
		}

		parent::setValue($property, $value);
	}

	#[Primary]
	#[Min(1)]
	public int $id = 0;

	#[NotEmpty]
	#[Min(1)]
	public int $userId;

	#[Nullable]
	public ?DateTime $dateCreate = null;

	#[Enum(CheckInType::class)]
	public int $entityType;

	#[Nullable]
	#[MaxLength(255)]
	public ?string $description = null;

	public array $messageIds = [];

	#[Nullable]
	#[MaxLength(255)]
	public ?string $address = null;

	#[NotEmpty]
	#[Range(-90, 90)]
	public float $latitude = 0.0;

	#[NotEmpty]
	#[Range(-180, 180)]
	public float $longitude = 0.0;

	#[Nullable]
	public ?int $userTimezone = null;

	#[Nullable]
	public ?array $dialogIds = null;

	public array $fileIds = [];

	#[Nullable]
	public ?string $snapshotUrl = null;

	#[Nullable]
	public ?string $locationExternalId = null;

	#[Nullable]
	public ?string $locationSourceCode = null;
}
