<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Result;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Command\CheckIn\Add;

class CheckInService
{
	private static ?self $instance = null;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function add(CheckInDto $checkInDto, array $uploadedFiles = []): Result
	{
		return (new Add())->execute($checkInDto, $uploadedFiles);
	}
}
