<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Chat\Update\UpdateService;
use Bitrix\Im\V2\Public\Command\Chat\Update\Tree\UpdateParentCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Collab\Integration\IM\ChatType;
use Bitrix\Socialnetwork\Collab\Integration\IM\Messenger;
use Bitrix\Socialnetwork\Integration\Im\ChatFactory;
use Bitrix\Socialnetwork\Item\Workgroup\Type;

class ConvertChatService
{
	public function convertChat(int $chatId, Type $type, string $groupTitle, bool $isOpened): Result
	{
		$result = new Result();

		if (!$this->isAvailable())
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		if ($chatId <= 0)
		{
			return $result->addError(new Error('Chat id should be greater than zero'));
		}

		$fields = $this->prepareFields($type, $groupTitle, $isOpened);

		$updateService = $this->getUpdateService($chatId, $fields);
		if ($updateService === null)
		{
			return $result->addError(new Error('Can not get update service'));
		}

		$updateResult = $updateService->updateChat();
		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());
		}

		return $result;
	}

	public function sendConvertMessageRich(int $groupId, int $userId): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		$this
			->getActionMessageFactory()
			->getActionMessage(ActionType::ConvertGroupToCollabRich, $groupId, $userId)
			->send()
		;
	}

	public function linkChatsToParent(array $chatIds, int $parentChatId): Result
	{
		$result = new Result();
		if (!$this->isAvailable())
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		if (empty($chatIds))
		{
			return $result;
		}

		return (new UpdateParentCommand($chatIds, $parentChatId))->run();
	}

	protected function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	protected function getActionMessageFactory(): ActionMessageFactory
	{
		return ActionMessageFactory::getInstance();
	}

	protected function getUpdateService(int $chatId, array $fields, bool $collabChatOnly = false): ?UpdateService
	{
		return Messenger::getUpdateService($chatId, $fields, $collabChatOnly);
	}

	protected function prepareFields(Type $type, string $groupTitle, bool $isOpened): array
	{
		$fields = ChatFactory::getUniqueFieldsByType($type);
		$fields['TITLE'] = ChatFactory::getChatTitle($groupTitle, $type);
		$fields['TYPE'] = ChatType::getChatTypeByGroupTypeAndAccessibility($type, $isOpened);

		return $fields;
	}
}
