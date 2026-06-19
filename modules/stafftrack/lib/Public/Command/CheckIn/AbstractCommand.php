<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Internal\Repository\Mapper\CheckInMapper;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;
use Bitrix\StaffTrack\Service\UserService;
abstract class AbstractCommand
{
	protected CheckInMapper $mapper;
	protected CheckInDto $checkInDto;

	public function __construct()
	{
		$this->init();
	}

	abstract public function execute(CheckInDto $checkInDto): Result;

	protected function init(): void
	{
		$this->mapper = new CheckInMapper();
	}

	public function sendPushToDepartment(Pull\PushCommand $command): void
	{
		$departmentIds = DepartmentProvider::getDepartmentIdsByUserId($this->checkInDto->userId);
		$checkIn = $this->checkInDto->toArray();
		$checkIn['description'] = Emoji::decode($checkIn['description'] ?? '');

		if (!empty($this->checkInDto->fileIds))
		{
			$checkIn['files'] = (new FileResolver())->resolve(
				$this->checkInDto->id,
				$this->checkInDto->fileIds,
				$this->checkInDto->userId,
			);
		}

		$payload = [
			'checkIn' => $checkIn,
			'user' => UserService::getInstance()->getUser($this->checkInDto->userId),
			'departmentIds' => $departmentIds,
		];

		Pull\PushSender::sendByTag(
			Pull\Tag::getUserTag($this->checkInDto->userId),
			$command,
			$payload,
		);

		$headIds = DepartmentProvider::getDepartmentHeadsIdsByUserId($this->checkInDto->userId);
		foreach ($headIds as $headId)
		{
			Pull\PushSender::sendByTag(
				Pull\Tag::getUserTag($headId),
				$command,
				$payload,
			);
		}
	}
}
