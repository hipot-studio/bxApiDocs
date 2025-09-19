<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Command;

use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Entity\UserField\UserFieldCollection;
use Bitrix\Intranet\Internal\Service\UserProfileService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class UpdateUserFieldsCommand extends AbstractCommand
{
	public function __construct(
		public int $userId,
		public readonly UserFieldCollection $userFieldCollection,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$userProfileService = UserProfileService::createByDefault();
			$handler = new UpdateUserFieldsHandler($userProfileService);
			$handler($this);
		}
		catch (UpdateFailedException $e)
		{
			$result->addErrors($e->getErrors()->toArray());
		}
		catch (ArgumentException $e)
		{
			$result->addError(new Error('Wrong arguments'));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [];
	}
}
