<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Internal\Integration\Im\Result\ChatCreationResult;
use Bitrix\Intranet\Internal\Integration\Im\SelfDeleteRequestChatService;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

class SendSelfDeleteRequestHandler
{
	public function __construct(
		private readonly SelfDeleteRequestChatService $selfDeleteRequestChatService,
	)
	{
	}

	public function __invoke(SendSelfDeleteRequestCommand $command): ChatCreationResult
	{
		try
		{
			$chatUsers = new UserCollection($command->user, ...$command->chatRecipients->all());
			$result = $this->selfDeleteRequestChatService->createWithUsers(
				$chatUsers,
				$command->user,
			);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return ChatCreationResult::createWithError(new Error(
				Loc::getMessage('INTRANET_PUBLIC_COMMAND_USER_SELF_DELETE_REQUEST_CHAT_ERROR')
					?? 'Failed to create the self-dismissal request chat. Please try again.',
				'SELF_DELETE_REQUEST_CHAT_EXCEPTION',
			));
		}

		return $result;
	}
}
