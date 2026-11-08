<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\CheckList;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Service\CheckList\ViewOptionService;

class SetHideCompletedCommandHandler
{
	public function __construct(
		private readonly ViewOptionService $viewOptionService,
	)
	{
	}

	public function __invoke(SetHideCompletedCommand $command): void
	{
		$this->viewOptionService->setCompletedHidden(Type::Template, $command->userId, $command->hide);
	}
}
