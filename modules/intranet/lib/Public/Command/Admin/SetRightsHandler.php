<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Admin;

use Bitrix\Bitrix24\Public\Command\FirstAdmin\TransferAdminRoleCommand;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Integration\Bitrix24\Admin\RestrictionService;
use Bitrix\Intranet\Util;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class SetRightsHandler
{
	public function __invoke(SetRightsCommand $command): Result
	{
		$isLimitExceeded = RestrictionService::isLimitExceeded();
		$currentUser = CurrentUser::get();
		$currentUserId = (int)$currentUser->getId();
		$result = new Result();

		if (
			Util::setAdminRights([
				'userId' => $command->userId,
				'currentUserId' => $currentUserId,
				'isCurrentUserAdmin' => $currentUser->isAdmin(),
			]) === false
		)
		{
			$result->addError(new Error('Failed to set admin rights'));
			return $result;
		}

		if ($isLimitExceeded && Loader::includeModule('bitrix24'))
		{
			$isCurrentUserFirstAdmin = ServiceLocator::getInstance()
				->get('intranet.service.user')
				->isFirstAdmin($currentUserId)
			;

			if ($isCurrentUserFirstAdmin)
			{
				return (new TransferAdminRoleCommand($currentUserId, $command->userId))->run();
			}

			return (new RemoveRightsCommand($currentUserId, $currentUserId))->run();
		}

		return $result;
	}
}
