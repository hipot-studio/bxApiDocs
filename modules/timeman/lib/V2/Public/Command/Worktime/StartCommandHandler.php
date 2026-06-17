<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Worktime;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordForm;
use Bitrix\Timeman\V2\Internal\Service\RecordService;

class StartCommandHandler
{
	public function __construct(private readonly RecordService $service)
	{
	}

	public function __invoke(StartCommand $command): Result
	{
		$recordForm = new RecordForm(
			userId: $command->userId,
			scheduleId: $command->scheduleId,
			shiftId: $command->shiftId,
			tasks: $command->tasks,
			ipOpen: $command->ipOpen,
			latitudeOpen: $command->latitudeOpen,
			longitudeOpen: $command->longitudeOpen,
			device: $command->device,
		);

		return $this->service->startWorktime($this->service->createLegacyRecordForm($recordForm));
	}
}
