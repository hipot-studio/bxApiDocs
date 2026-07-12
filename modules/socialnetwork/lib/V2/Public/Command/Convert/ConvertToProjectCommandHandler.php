<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Convert;

use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertResult;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\ConvertService;

class ConvertToProjectCommandHandler
{
	public function __construct(
		private readonly ConvertService $convertService,
	)
	{

	}

	public function __invoke(ConvertToProjectCommand $command): ConvertResult
	{
		return $this->convertService->convert(
			groupId: $command->groupId,
			userId: $command->userId,
		);
	}
}
