<?php

namespace Bitrix\StaffTrack\Service;

use Bitrix\Main;
use Bitrix\StaffTrack\Model\ShiftCollection;
use Bitrix\StaffTrack\Provider\ShiftProvider;
use Bitrix\StaffTrack\Shift\Command\Add;
use Bitrix\StaffTrack\Shift\Command\Delete;
use Bitrix\StaffTrack\Shift\Command\Update;
use Bitrix\StaffTrack\Shift\ShiftDto;

class ShiftService
{
	private static array $instances = [];

	public static function getInstance(int $userId)
	{
		if (!isset(self::$instances[$userId]))
		{
			self::$instances[$userId] = new self($userId);
		}

		return self::$instances[$userId];
	}

	private function __construct(int $userId)
	{
	}

	public function add(ShiftDto $shiftDto): Main\Result
	{
		return (new Add())->execute($shiftDto);
	}

	public function update(ShiftDto $shiftDto): Main\Result
	{
		return (new Update())->execute($shiftDto);
	}

	public function delete(ShiftDto $shiftDto): Main\Result
	{
		return (new Delete())->execute($shiftDto);
	}
}