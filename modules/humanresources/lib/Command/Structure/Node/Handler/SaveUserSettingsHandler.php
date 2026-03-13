<?php

namespace Bitrix\HumanResources\Command\Structure\Node\Handler;

use Bitrix\HumanResources\Command\Structure\Node\SaveUserSettingsCommand;
use Bitrix\HumanResources\Internals;
use Bitrix\Main\Result;

class SaveUserSettingsHandler
{
	private Internals\Service\Structure\UserSettingsService $userSettingsService;

	public function __construct()
	{
		$this->userSettingsService = Internals\Service\Container::getUserSettingsService();
	}

	public function __invoke(SaveUserSettingsCommand $command): Result
	{
		return $this->userSettingsService->save($command->user->id, $command->settings);
	}
}
