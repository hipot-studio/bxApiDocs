<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\State;

use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;
use Bitrix\Tasks\V2\Internal\Service\State\StateFlagsService;

class SetStateFlagsHandler
{
	public function __construct(
		private readonly StateFlagsService $stateFlagsService,
	)
	{

	}

	public function __invoke(SetStateFlagsCommand $command): void
	{
		$this->stateFlagsService->set(
			$command->flags,
			OptionDictionary::StateFlagsTemplate,
			$command->userId,
		);
	}
}
