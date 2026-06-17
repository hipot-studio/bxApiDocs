<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Worktime;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordForm;
use Bitrix\Timeman\V2\Internal\Service\RecordService;

class PauseCommandHandler
{
	public function __construct(private readonly \Bitrix\Timeman\V2\Internal\Service\RecordService $service)
	{
	}

	public function __invoke(PauseCommand $command): Result
	{
		$recordForm = new RecordForm(
			userId: $command->userId,
			recordId: $command->recordId,
			ipClose: $command->ipClose,
			latitudeClose: $command->latitudeClose,
			longitudeClose: $command->longitudeClose,
			device: $command->device,
		);

		return $this->service->pauseWorktime($this->service->createLegacyRecordForm($recordForm));
	}
}
