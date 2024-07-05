<?php

namespace Bitrix\StaffTrack\Shift;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internals\AbstractDto;
use Bitrix\StaffTrack\Internals\Attribute\Min;
use Bitrix\StaffTrack\Internals\Attribute\NotEmpty;
use Bitrix\StaffTrack\Internals\Attribute\Nullable;
use Bitrix\StaffTrack\Internals\Attribute\Primary;
use ReflectionProperty;

/**
 * @method self setId(int $id)
 * @method self setUserId(int $userId)
 * @method self setShiftDate(Date $shiftDate)
 * @method self setDateCreate(DateTime $dateCreate)
 * @method self setTimezoneOffset(int $timezoneOffset)
 * @method self setStatus(int $status)
 * @method self setLocation(string $location)
 * @method self setDialogId(int $dialogId)
 * @method self setMessage(string $message)
 * @method self setGeoImageUrl(string $geoImageUrl)
 * @method self setCancelReason(string $cancelReason)
 * @method self setDateCancel(DateTime $dateCancel)
 * @method self setSkipTm(bool $skipTm)
 * @method self setSkipOptions(bool $skipOptions)
 * @method self setSkipCounter(bool $skipCounter)
 */
final class ShiftDto extends AbstractDto
{
	#[Primary]
	#[Min(0)]
	public int $id = 0;

	#[Min(1)]
	public int $userId;

	#[NotEmpty]
	public Date $shiftDate;

	#[Nullable]
	public ?DateTime $dateCreate = null;

	#[NotEmpty]
	public int $timezoneOffset = 0;

	#[Min(1)]
	public int $status;

	#[Nullable]
	public string $location = '';

	#[Nullable]
	public ?string $dialogId = null;

	#[Nullable]
	public ?string $message = null;

	#[Nullable]
	public ?int $imageFileId = null;

	#[Nullable]
	public ?string $geoImageUrl = null;

	#[Nullable]
	public ?string $address = null;
	#[Nullable]
	public ?string $cancelReason = null;

	#[Nullable]
	public ?DateTime $dateCancel = null;

	public bool $skipTm = false;

	public bool $skipOptions = false;

	public bool $skipCounter = false;

	protected function setValue(ReflectionProperty $property, mixed $value): void
	{
		if (is_string($value) && $property->getName() === 'shiftDate')
		{
			try
			{
				$dateTime = new \Bitrix\StaffTrack\Type\DateTime($value);
				$this->shiftDate = $dateTime->getDate();
				$this->timezoneOffset = $dateTime->getOffset();
			}
			catch (ObjectException) {}
		}
		else
		{
			parent::setValue($property, $value);
		}
	}
}