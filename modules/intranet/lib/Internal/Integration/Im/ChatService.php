<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\Cleanup\ChatContentCollector;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class ChatService
{
	public const ERROR_NO_ACTIVE_USER = 'ERROR_NO_ACTIVE_USER';
	private const LOCK_TIMEOUT = 10;

	/**
	 * @param UserCollection $userCollection
	 * @param string $title
	 * @param bool $skipAddMessage
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @return Result
	 */
	public function createGroupChatWithUsers(
		UserCollection $userCollection,
		string $title = '',
		bool $skipAddMessage = false,
	): Result
	{
		$fieldsResult = $this->buildGroupChatFields($userCollection, $title, $skipAddMessage);
		if (!$fieldsResult->isSuccess())
		{
			return $fieldsResult;
		}

		return \Bitrix\Im\V2\Service\Locator::getMessenger()->createChat($fieldsResult->getData());
	}

	public function createUniqueGroupChatWithUsers(
		UserCollection $userCollection,
		int $authorId,
		string $entityType,
		string $entityId,
		string $title = '',
		bool $skipAddMessage = false,
	): Result
	{
		$fieldsResult = $this->buildGroupChatFields($userCollection, $title, $skipAddMessage);
		if (!$fieldsResult->isSuccess())
		{
			return $fieldsResult;
		}

		$fields = [
			...$fieldsResult->getData(),
			'AUTHOR_ID' => $authorId,
			'ENTITY_TYPE' => $entityType,
			'ENTITY_ID' => $entityId,
		];

		return ChatFactory::getInstance()
			->withContextUser($authorId)
			->addUniqueChatPerAuthor($fields)
		;
	}

	public function deleteCreatedChat(int $chatId, int $authorId): void
	{
		$this->includeImModule();

		(new ChatContentCollector($chatId))->deleteChat($authorId);
	}

	public function executeWithLock(string $lockName, \Closure $operation): mixed
	{
		if ($lockName === '')
		{
			throw new \InvalidArgumentException('Lock name must not be empty');
		}

		$connection = Application::getConnection();
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			throw new \RuntimeException('Failed to acquire database lock');
		}

		try
		{
			return $operation();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public function findMessageIdByComponentStage(
		int $chatId,
		int $authorId,
		string $componentId,
		string $stageId,
	): int
	{
		if ($chatId <= 0 || $authorId <= 0 || $componentId === '' || $stageId === '')
		{
			return 0;
		}

		$this->includeImModule();

		$messageRows = MessageParamTable::query()
			->setSelect(['MESSAGE_ID'])
			->where('MESSAGE.CHAT_ID', $chatId)
			->where('MESSAGE.AUTHOR_ID', $authorId)
			->where('PARAM_NAME', Params::COMPONENT_ID)
			->where('PARAM_VALUE', $componentId)
			->setOrder(['MESSAGE_ID' => 'DESC'])
			->fetchAll()
		;
		$messageIds = array_map('intval', array_column($messageRows, 'MESSAGE_ID'));
		if ($messageIds === [])
		{
			return 0;
		}

		$stageIdsByMessageId = [];
		$componentParamRows = MessageParamTable::query()
			->setSelect(['MESSAGE_ID', 'PARAM_JSON'])
			->whereIn('MESSAGE_ID', $messageIds)
			->where('PARAM_NAME', Params::COMPONENT_PARAMS)
			->fetchAll()
		;
		foreach ($componentParamRows as $componentParamRow)
		{
			try
			{
				$componentParams = Json::decode((string)($componentParamRow['PARAM_JSON'] ?? ''));
			}
			catch (ArgumentException)
			{
				continue;
			}

			if (is_array($componentParams))
			{
				$stageIdsByMessageId[(int)$componentParamRow['MESSAGE_ID']] = $componentParams['STAGE_ID'] ?? null;
			}
		}

		foreach ($messageIds as $messageId)
		{
			if (($stageIdsByMessageId[$messageId] ?? null) === $stageId)
			{
				return $messageId;
			}
		}

		return 0;
	}

	private function buildGroupChatFields(
		UserCollection $userCollection,
		string $title,
		bool $skipAddMessage,
	): Result
	{
		$this->includeImModule();

		$userCollection = $userCollection->filter(
			fn (User $user) => $user->getActive(),
		);

		if ($userCollection->empty())
		{
			$error = new Error(
				Loc::getMessage('INTRANET_INTEGRATION_CREATE_CHAT_ERROR_NO_ACTIVE_USER'),
				self::ERROR_NO_ACTIVE_USER,
			);

			return (new Result())->addError($error);
		}

		$fields = [
			'USERS' => $userCollection->getIds(),
		];

		if ($title !== '')
		{
			$fields['TITLE'] = $title;
		}

		if ($skipAddMessage)
		{
			$fields['SKIP_ADD_MESSAGE'] = 'Y';
		}

		return (new Result())->setData($fields);
	}

	private function includeImModule(): void
	{
		if (!Loader::includeModule('im'))
		{
			throw new LoaderException('Module im is not installed');
		}
	}
}
