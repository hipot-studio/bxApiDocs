<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\StaffTrack\Dictionary\OfficeGeoRadius;
use Bitrix\StaffTrack\Internals\AbstractDto;
use Bitrix\StaffTrack\Internals\Attribute\Enum;
use Bitrix\StaffTrack\Internals\Attribute\MaxLength;
use Bitrix\StaffTrack\Internals\Attribute\Min;
use Bitrix\StaffTrack\Internals\Attribute\NotEmpty;
use Bitrix\StaffTrack\Internals\Attribute\Nullable;
use Bitrix\StaffTrack\Internals\Attribute\Primary;
use Bitrix\StaffTrack\Internals\Attribute\Range;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;

/**
 * The card of an office as a client sends it. Photos are not a field of the card: they travel beside
 * the DTO as tokens of the uploader.
 *
 * Every InvalidDtoException raised here carries a machine code of the card contract as its message -
 * the domain answers with a code, and the phrase to show is picked by the client.
 *
 * @method self setId(int $id)
 * @method self setTitle(string $title)
 * @method self setLatitude(float $latitude)
 * @method self setLongitude(float $longitude)
 * @method self setGeoRadius(int $geoRadius)
 * @method self setAddress(?string $address)
 * @method self setWorkTimeFrom(?int $workTimeFrom)
 * @method self setWorkTimeTo(?int $workTimeTo)
 */
final class OfficeDto extends AbstractDto
{
	public const ERROR_INVALID_ID = 'STAFFTRACK_OFFICE_INVALID_ID';
	public const ERROR_INVALID_TITLE = 'STAFFTRACK_OFFICE_INVALID_TITLE';
	public const ERROR_INVALID_COORDS = 'STAFFTRACK_OFFICE_INVALID_COORDS';
	public const ERROR_INVALID_RADIUS = 'STAFFTRACK_OFFICE_INVALID_RADIUS';
	public const ERROR_INVALID_ADDRESS = 'STAFFTRACK_OFFICE_INVALID_ADDRESS';
	public const ERROR_INVALID_WORK_TIME = 'STAFFTRACK_OFFICE_INVALID_WORK_TIME';

	/** Answer of last resort, for a field added to the card without a code of its own. */
	private const ERROR_INVALID_FIELD = 'STAFFTRACK_OFFICE_INVALID_FIELD';

	private const ERROR_CODE_BY_PROPERTY = [
		'id' => self::ERROR_INVALID_ID,
		'title' => self::ERROR_INVALID_TITLE,
		'latitude' => self::ERROR_INVALID_COORDS,
		'longitude' => self::ERROR_INVALID_COORDS,
		'geoRadius' => self::ERROR_INVALID_RADIUS,
		'address' => self::ERROR_INVALID_ADDRESS,
		'workTimeFrom' => self::ERROR_INVALID_WORK_TIME,
		'workTimeTo' => self::ERROR_INVALID_WORK_TIME,
	];

	#[Primary]
	#[Min(1)]
	public int $id = 0;

	#[NotEmpty]
	#[MaxLength(255)]
	public string $title;

	// No default value on the coordinates on purpose: a property left at its default passes validation
	// untouched, and an office at 0.0 would then be an office with no coordinates at all.
	#[Range(-90, 90)]
	public float $latitude;

	#[Range(-180, 180)]
	public float $longitude;

	#[Enum(OfficeGeoRadius::class)]
	public int $geoRadius;

	#[Nullable]
	#[MaxLength(500)]
	public ?string $address = null;

	/** Minutes from midnight, as the storage keeps them. */
	#[Nullable]
	#[Range(0, 1439)]
	public ?int $workTimeFrom = null;

	#[Nullable]
	#[Range(0, 1439)]
	public ?int $workTimeTo = null;

	/**
	 * @throws InvalidDtoException
	 */
	public function validateAdd(): void
	{
		if ($this->id > 0)
		{
			throw new InvalidDtoException(self::ERROR_INVALID_ID);
		}

		$this->validateAddProperties();
		$this->validateWorkTimePair();
	}

	/**
	 * The card is written whole, so an update is held to the same completeness as a creation.
	 *
	 * @throws InvalidDtoException
	 */
	public function validateUpdate(): void
	{
		$this->validatePrimary();
		$this->validateAddProperties();
		$this->validateWorkTimePair();
	}

	/**
	 * @throws InvalidDtoException
	 */
	public function validateDelete(): void
	{
		$this->validatePrimary();
	}

	/**
	 * @throws InvalidDtoException
	 */
	public function validatePrimary(): void
	{
		try
		{
			parent::validatePrimary();
		}
		catch (InvalidDtoException)
		{
			throw new InvalidDtoException(self::ERROR_INVALID_ID);
		}
	}

	/**
	 * @throws InvalidDtoException
	 */
	protected function validateProperty(string $propertyName): void
	{
		try
		{
			parent::validateProperty($propertyName);
		}
		catch (InvalidDtoException)
		{
			throw new InvalidDtoException(self::ERROR_CODE_BY_PROPERTY[$propertyName] ?? self::ERROR_INVALID_FIELD);
		}
	}

	/**
	 * Working hours are a pair: an office either states both ends of its day or states none.
	 *
	 * @throws InvalidDtoException
	 */
	private function validateWorkTimePair(): void
	{
		if (($this->workTimeFrom === null) !== ($this->workTimeTo === null))
		{
			throw new InvalidDtoException(self::ERROR_INVALID_WORK_TIME);
		}
	}
}
