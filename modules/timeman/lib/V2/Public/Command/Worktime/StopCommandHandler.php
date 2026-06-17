<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Worktime;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordForm;
use Bitrix\Timeman\V2\Internal\Service\RecordService;

class StopCommandHandler
{
	public function __construct(private readonly RecordService $service)
	{
	}

	public function __invoke(StopCommand $command): Result
	{
		$recordForm = new RecordForm(
			userId: $command->userId,
			recordId: $command->recordId,
			reason: $command->reason,
			stopTimestamp: $command->stopTimestamp,
			ipClose: $command->ipClose,
			latitudeClose: $command->latitudeClose,
			longitudeClose: $command->longitudeClose,
			device: $command->device,
		);

		return $this->service->stopWorktime($this->service->createLegacyRecordForm($recordForm));
	}
}
