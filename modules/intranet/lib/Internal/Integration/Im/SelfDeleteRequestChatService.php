<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Im\Result\ChatCreationResult;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final readonly class SelfDeleteRequestChatService
{
	private const CHAT_ENTITY_TYPE = 'INTRANET_SELF_DELETE_REQUEST';
	private const MESSAGE_COMPONENT_ID = 'AdminMessage';
	private const MESSAGE_STAGE_ID = 'selfDeleteRequest';
	private const GENDER_MALE = 'M';
	private const GENDER_FEMALE = 'F';

	public function __construct(
		private ?ChatService $chatService = null,
		private ?\Closure $messageSender = null,
	)
	{
	}

	public function createWithUsers(UserCollection $userCollection, User $author): ChatCreationResult
	{
		$authorId = (int)$author->getId();
		$entityId = $this->getRequestEntityId($userCollection, $author);

		return $this->getChatService()->executeWithLock(
			$this->getRequestLockName($entityId, $authorId),
			fn(): ChatCreationResult => $this->createWithUsersLocked(
				$userCollection,
				$author,
				$entityId,
			),
		);
	}

	private function createWithUsersLocked(
		UserCollection $userCollection,
		User $author,
		string $entityId,
	): ChatCreationResult
	{
		$chatResult = $this->getChatService()->createUniqueGroupChatWithUsers(
			userCollection: $userCollection,
			authorId: (int)$author->getId(),
			entityType: self::CHAT_ENTITY_TYPE,
			entityId: $entityId,
			title: $this->getRequestChatTitle($author),
			skipAddMessage: true,
		);

		if (!$chatResult->isSuccess())
		{
			return ChatCreationResult::createWithErrors($chatResult->getErrors());
		}

		$chatId = $this->extractChatId($chatResult);

		if ($chatId <= 0)
		{
			return $this->createChatCreationFailedResult();
		}

		$alreadyExists = $this->isAlreadyExistingChat($chatResult);
		if ($alreadyExists)
		{
			$messageId = $this->getChatService()->findMessageIdByComponentStage(
				$chatId,
				(int)$author->getId(),
				self::MESSAGE_COMPONENT_ID,
				self::MESSAGE_STAGE_ID,
			);
			if ($messageId > 0)
			{
				return new ChatCreationResult($chatId, $messageId, alreadyExists: true);
			}
		}

		$messageResult = $this->sendRequestMessage($chatId, $author);

		if (!$messageResult->isSuccess())
		{
			if (!$alreadyExists)
			{
				$this->deleteEmptyChat($chatId, $author);
			}

			return ChatCreationResult::createWithErrors($messageResult->getErrors());
		}

		$messageId = (int)($messageResult->getData()['MESSAGE_ID'] ?? 0);
		if ($messageId <= 0)
		{
			if (!$alreadyExists)
			{
				$this->deleteEmptyChat($chatId, $author);
			}

			return $this->createMessageNotSentResult();
		}

		return new ChatCreationResult(
			$chatId,
			$messageId,
			$alreadyExists,
		);
	}

	private function sendRequestMessage(int $chatId, User $author): Result
	{
		$messageFields = [
			'TO_CHAT_ID' => $chatId,
			'FROM_USER_ID' => $author->getId(),
			'SYSTEM' => 'Y',
			'MESSAGE_TYPE' => 'C',
			'MESSAGE' => $this->getRequestMessageText($author),
			'PARAMS' => $this->buildRequestMessageParams($author),
		];
		$messageId = $this->messageSender
			? ($this->messageSender)($messageFields)
			: \CIMMessenger::Add($messageFields);

		if ((int)$messageId <= 0)
		{
			return $this->createMessageNotSentResult();
		}

		return (new Result())->setData([
			'MESSAGE_ID' => (int)$messageId,
		]);
	}

	private function buildRequestMessageParams(User $author): array
	{
		return [
			'COMPONENT_ID' => self::MESSAGE_COMPONENT_ID,
			'COMPONENT_PARAMS' => [
				'STAGE_ID' => self::MESSAGE_STAGE_ID,
				'INITIATOR' => [
					'ID' => $author->getId(),
					'NAME' => $author->getFormattedName(),
					'GENDER' => $author->getGender(),
				],
			],
		];
	}

	private function getRequestMessageText(User $author): string
	{
		return Loc::getMessage(
			$this->getRequestMessageCode($author),
			[
				'#USER_NAME#' => $author->getFormattedName(false, false),
			],
		) ?: Loc::getMessage(
			'INTRANET_INTERNAL_INTEGRATION_IM_SELF_DELETE_REQUEST_MESSAGE',
			[
				'#USER_NAME#' => $author->getFormattedName(false, false),
			],
		) ?: sprintf(
			'%s has requested dismissal in Bitrix24. An administrator has to process the dismissal manually.',
			$author->getFormattedName(false, false),
		);
	}

	private function getRequestMessageCode(User $author): string
	{
		return match ($author->getGender())
		{
			self::GENDER_MALE => 'INTRANET_INTERNAL_INTEGRATION_IM_SELF_DELETE_REQUEST_MESSAGE_M',
			self::GENDER_FEMALE => 'INTRANET_INTERNAL_INTEGRATION_IM_SELF_DELETE_REQUEST_MESSAGE_F',
			default => 'INTRANET_INTERNAL_INTEGRATION_IM_SELF_DELETE_REQUEST_MESSAGE',
		};
	}

	private function getRequestChatTitle(User $author): string
	{
		$userName = $author->getFormattedName(useLogin: true, useHtmlSpec: false);

		return Loc::getMessage(
			'INTRANET_INTERNAL_INTEGRATION_IM_SELF_DELETE_REQUEST_CHAT_TITLE',
			[
				'#USER_NAME#' => $userName,
			],
		) ?: sprintf('%s: profile deletion', $userName);
	}

	private function extractChatId(Result $result): int
	{
		if ($result instanceof \Bitrix\Im\V2\Result)
		{
			return (int)($result->getResult()['CHAT_ID'] ?? 0);
		}

		return (int)($result->getData()['CHAT_ID'] ?? 0);
	}

	private function isAlreadyExistingChat(Result $result): bool
	{
		if ($result instanceof \Bitrix\Im\V2\Result)
		{
			return ($result->getResult()['ALREADY_EXISTS'] ?? false) === true;
		}

		return ($result->getData()['ALREADY_EXISTS'] ?? false) === true;
	}

	private function getRequestEntityId(UserCollection $userCollection, User $author): string
	{
		$authorId = (int)$author->getId();
		$recipientIds = array_values(array_filter(
			$userCollection->getIds(),
			static fn(int $userId): bool => $userId !== $authorId,
		));
		sort($recipientIds, SORT_NUMERIC);

		return hash('sha256', implode(':', $recipientIds));
	}

	private function getRequestLockName(string $entityId, int $authorId): string
	{
		return hash('sha256', self::CHAT_ENTITY_TYPE . ':' . $entityId . ':' . $authorId);
	}

	private function deleteEmptyChat(int $chatId, User $author): void
	{
		try
		{
			$this->getChatService()->deleteCreatedChat($chatId, (int)$author->getId());
		}
		catch (\Throwable $exception)
		{
			\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	private function createChatCreationFailedResult(): ChatCreationResult
	{
		return ChatCreationResult::createWithError(new Error(
			'Self delete request chat was not created',
			'SELF_DELETE_REQUEST_CHAT_NOT_CREATED',
		));
	}

	private function createMessageNotSentResult(): ChatCreationResult
	{
		return ChatCreationResult::createWithError(new Error(
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_IM_SELF_DELETE_REQUEST_MESSAGE_NOT_SENT')
				?? 'Failed to send the self-dismissal request message.',
			'INTRANET_INTERNAL_INTEGRATION_IM_SELF_DELETE_REQUEST_MESSAGE_NOT_SENT',
		));
	}

	private function getChatService(): ChatService
	{
		return $this->chatService ?? new ChatService();
	}
}
