<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Controllers;

use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Tasks\Deadline\Command\SetDefaultDeadlineCommand;
use Bitrix\Tasks\Deadline\Controllers\Dto\DeadlineDto;
use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\InvalidCommandException;
use Bitrix\Tasks\Rest\Controllers\Trait\ErrorResponseTrait;
use InvalidArgumentException;

class Deadline extends Controller
{
	use ErrorResponseTrait;

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				DeadlineDto::class,
				'deadlineData',
				function($className, $deadlineData): ?DeadlineDto {
					if (isset($deadlineData['isExactTime']))
					{
						$deadlineData['isExactTime'] = $deadlineData['isExactTime'] === 'Y';
					}

					return DeadlineDto::createFromArray($deadlineData);
				}
			),
		];
	}

	/**
	 * @restMethod tasks.deadline.Deadline.setDefault
	 */
	public function setDefaultAction(DeadlineDto $deadlineData): bool
	{
		try
		{
			$deadlineData->validate();

			$userId = (int)CurrentUser::get()->getId();

			$entity = new DeadlineUserOption(
				userId: $userId,
				defaultDeadlineInSeconds: $deadlineData->default,
				isExactDeadlineTime: $deadlineData->isExactTime,
			);

			$command = new SetDefaultDeadlineCommand($entity);
			$command->run();

			return true;
		}
		catch (InvalidCommandException|InvalidArgumentException $e)
		{
			$this->buildErrorResponse($e->getMessage());

			return false;
		}
	}
}
