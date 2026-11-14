<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Public\Command\Office\OfficeDto;

final class OfficeAddressResolver
{
	public function __construct(private readonly OfficeAddressGeocoder $geocoder)
	{
	}

	public function needsGeocoding(OfficeDto $officeDto, bool $addressEdited, ?StoredOfficeAddress $stored): bool
	{
		return $this->keptAddress($officeDto, $addressEdited, $stored) === null;
	}

	public function geocode(OfficeDto $officeDto): ?string
	{
		return $this->geocoder->shortAddress($officeDto->latitude, $officeDto->longitude);
	}

	public function resolve(
		OfficeDto $officeDto,
		bool $addressEdited,
		?StoredOfficeAddress $stored,
		?string $geocoded,
	): OfficeAddress
	{
		return $this->keptAddress($officeDto, $addressEdited, $stored)
			?? $this->shortenedAddress($officeDto, $geocoded)
		;
	}

	public function resolveRepeat(
		OfficeDto $officeDto,
		bool $addressEdited,
		?StoredOfficeAddress $stored,
		?string $geocoded,
	): OfficeAddress
	{
		return $this->keptAddress($officeDto, $addressEdited, $stored)
			?? $this->writtenAddress($officeDto, $stored)
			?? $this->shortenedAddress($officeDto, $geocoded)
		;
	}

	private function keptAddress(
		OfficeDto $officeDto,
		bool $addressEdited,
		?StoredOfficeAddress $stored,
	): ?OfficeAddress
	{
		$text = $this->formText($officeDto);

		if ($addressEdited && $text !== '')
		{
			return new OfficeAddress($text);
		}

		if ($stored !== null && $this->isSamePoint($officeDto, $stored) && $text === trim($stored->address ?? ''))
		{
			return new OfficeAddress($stored->address);
		}

		return null;
	}

	private function writtenAddress(OfficeDto $officeDto, ?StoredOfficeAddress $stored): ?OfficeAddress
	{
		if ($stored === null || !$this->isSamePoint($officeDto, $stored))
		{
			return null;
		}

		return new OfficeAddress($stored->address);
	}

	private function shortenedAddress(OfficeDto $officeDto, ?string $geocoded): OfficeAddress
	{
		if ($geocoded !== null)
		{
			return new OfficeAddress($geocoded);
		}

		$text = $this->formText($officeDto);

		return new OfficeAddress($text === '' ? null : $text);
	}

	private function isSamePoint(OfficeDto $officeDto, StoredOfficeAddress $stored): bool
	{
		return OfficeCardVersion::isSamePoint(
			$officeDto->latitude,
			$officeDto->longitude,
			$stored->latitude,
			$stored->longitude,
		);
	}

	private function formText(OfficeDto $officeDto): string
	{
		return trim($officeDto->address ?? '');
	}
}
