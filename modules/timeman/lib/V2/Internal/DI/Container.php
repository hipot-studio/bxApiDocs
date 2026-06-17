<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\DI;

use Bitrix\Timeman\V2\Internal\Repository\FileRepository;
use Bitrix\Timeman\V2\Internal\Repository\FullReportRepository;
use Bitrix\Timeman\V2\Internal\Repository\InMemoryUserRepository;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\FileMapper;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\UserMapper;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ReportRepository;
use Bitrix\Timeman\V2\Internal\Repository\ScheduleRepository;
use Bitrix\Timeman\V2\Internal\Repository\ShiftRepository;
use Bitrix\Timeman\V2\Internal\Repository\UserRepository;
use Bitrix\Timeman\V2\Internal\Service\FullReportFacade;
use Bitrix\Timeman\V2\Internal\Service\FullReportUserService;
use Bitrix\Timeman\V2\Internal\Service\NameService;
use Bitrix\Timeman\V2\Internal\Service\PhotoService;
use Bitrix\Timeman\V2\Internal\Service\RecordService;
use Bitrix\Timeman\V2\Internal\Service\UserService;

class Container extends AbstractContainer
{
	public function getRecordRepository(): RecordRepository
	{
		return $this->get(RecordRepository::class);
	}

	public function getReportRepository(): ReportRepository
	{
		return $this->get(ReportRepository::class);
	}

	public function getFullReportRepository(): FullReportRepository
	{
		return $this->get(FullReportRepository::class);
	}

	public function getFullReportFacade(): FullReportFacade
	{
		return $this->get(FullReportFacade::class);
	}

	public function getUserRepository(): UserRepository
	{
		return $this->get(UserRepository::class);
	}

	public function getFileRepository(): FileRepository
	{
		return $this->get(FileRepository::class);
	}

	public function getInMemoryUserRepository(): InMemoryUserRepository
	{
		return $this->get(InMemoryUserRepository::class);
	}

	public function getFileMapper(): FileMapper
	{
		return $this->get(FileMapper::class);
	}

	public function getUserMapper(): UserMapper
	{
		return $this->get(UserMapper::class);
	}

	public function getPhotoService(): PhotoService
	{
		return $this->get(PhotoService::class);
	}

	public function getNameService(): NameService
	{
		return $this->get(NameService::class);
	}

	public function getUserService(): UserService
	{
		return $this->get(UserService::class);
	}

	public function getFullReportUserService(): FullReportUserService
	{
		return $this->get(FullReportUserService::class);
	}

	public function getRecordService(): RecordService
	{
		return $this->get(RecordService::class);
	}

	public function getScheduleRepository(): ScheduleRepository
	{
		return $this->get(ScheduleRepository::class);
	}

	public function getShiftRepository(): ShiftRepository
	{
		return $this->get(ShiftRepository::class);
	}
}
