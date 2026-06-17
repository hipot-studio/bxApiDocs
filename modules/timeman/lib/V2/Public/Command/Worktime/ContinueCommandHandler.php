<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Worktime;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordForm;
use Bitrix\Timeman\V2\Internal\Service\RecordService;

class ContinueCommandHandler
{
	public function __construct(private readonly RecordService $service)
	{
	}

	public function __invoke(ContinueCommand $command): Result
	{
		$recordForm = new RecordForm(
			userId: $command->userId,
			recordId: $command->recordId,
			device: $command->device,
		);

		return $this->service->continueWorktime($this->service->createLegacyRecordForm($recordForm));
	}
}
