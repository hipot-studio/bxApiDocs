<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Im;

use Bitrix\Im;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Design;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlocksBuilder;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Builder;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\Config\Background;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons\ButtonLine;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons\LinkButton;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;

class ConnectionRequestChat
{
	public const ENTITY_TYPE = 'MAIL_CONNECTION_REQUEST_CHAT';

	public function getOrCreateValidChat(int $requesterId, array $adminIds): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		$reusableChatId = $this->findReusableChatId($requesterId, $adminIds);
		if ($reusableChatId > 0)
		{
			return $result->setData([
				'chatId' => $reusableChatId,
				'alreadyExists' => true,
				'needsRequestMessage' => false,
			]);
		}

		$chatResult = $this->getOrCreateChat($requesterId, $adminIds);
		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$chatData = $chatResult->getData();
		$alreadyExists = (bool)($chatData['alreadyExists'] ?? false);

		return $result->setData([
			'chatId' => (int)($chatData['chatId'] ?? 0),
			'alreadyExists' => $alreadyExists,
			'needsRequestMessage' => !$alreadyExists,
		]);
	}

	public function getOrCreateChat(int $requesterId, array $adminIds): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		$userName = $this->getUserFormattedName($requesterId);

		$chatTitle = Loc::getMessage(
			'MAIL_CONNECTION_REQUEST_CHAT_TITLE',
			['#USER_NAME#' => $userName],
		);

		$factory = ChatFactory::getInstance()->withContextUser($requesterId);
		$chatResult = $factory->addUniqueChat([
			'TYPE' => Im\V2\Chat::IM_TYPE_CHAT,
			'ENTITY_TYPE' => self::ENTITY_TYPE,
			'ENTITY_ID' => (string)$requesterId,
			'USERS' => array_merge([$requesterId], $adminIds),
			'TITLE' => $chatTitle,
			'SKIP_ADD_MESSAGE' => 'Y',
			'AUTHOR_ID' => $requesterId,
		]);

		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$chatData = $chatResult->getData();
		$result->setData([
			'chatId' => $chatResult->getChatId(),
			'alreadyExists' => (bool)($chatData['ALREADY_EXISTS'] ?? $chatData['RESULT']['ALREADY_EXISTS'] ?? false),
		]);

		return $result;
	}

	public function sendRequestMessage(int $chatId, int $requesterId, string $comment): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$chat = Im\V2\Chat::getInstance($chatId);
		if ($chat === null)
		{
			return;
		}

		$builder = $this->buildRequestCard($chat, $requesterId, $comment);

		$this->sendCardMessage(
			$chat,
			$requesterId,
			$builder,
			(string)Loc::getMessage('MAIL_CONNECTION_REQUEST_CHAT_CARD_FALLBACK'),
		);
	}

	public function sendRejectedMessage(int $chatId, int $adminId): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$this->sendMessage($chatId, $adminId, Loc::getMessage(
			'MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_REJECTED',
		));
	}

	public function sendCompletedMessage(int $chatId, int $adminId, string $email): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$this->sendMessage($chatId, $adminId, Loc::getMessage(
			'MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_COMPLETED',
			['#EMAIL#' => $email],
		));
	}

	public function sendCancelledMessage(int $chatId, int $requesterId): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$this->sendMessage($chatId, $requesterId, Loc::getMessage('MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_CANCELLED'));
	}

	private function sendMessage(
		int $chatId,
		int $authorId,
		string $messageText,
	): void
	{
		$chat = Im\V2\Chat::getInstance($chatId);
		if ($chat === null)
		{
			return;
		}

		$message = new Im\V2\Message();
		$message
			->setMessage($messageText)
			->setAuthorId($authorId)
			->setContextUser($authorId)
		;

		$chat->withContextUser($authorId)->sendMessage($message);
	}

	private function sendCardMessage(
		Im\V2\Chat $chat,
		int $authorId,
		BlocksBuilder $builder,
		string $fallbackText,
	): void
	{
		$message = new Im\V2\Message();
		$message
			->setMessage($fallbackText)
			->setContextUser($authorId)
			->markAsSystem(true)
			->setBlocksBuilder($builder)
		;

		$chat->withContextUser($authorId)->sendMessage($message);
	}

	private function buildRequestCard(Im\V2\Chat $chat, int $requesterId, string $comment): BlocksBuilder
	{
		$userMention = sprintf('[USER=%d]%s[/USER]', $requesterId, $this->getUserFormattedName($requesterId));

		if ($comment !== '')
		{
			$cardText = (string)Loc::getMessage(
				'MAIL_CONNECTION_REQUEST_CHAT_CARD_TEXT_WITH_COMMENT',
				['#USER_NAME#' => $userMention, '#COMMENT#' => $comment],
			);
		}
		else
		{
			$cardText = (string)Loc::getMessage(
				'MAIL_CONNECTION_REQUEST_CHAT_CARD_TEXT',
				['#USER_NAME#' => $userMention],
			);
		}

		$buttons = Buttons::create()->addButtonLine(
			ButtonLine::create()->addButton(
				LinkButton::create(
					(string)Loc::getMessage('MAIL_CONNECTION_REQUEST_CHAT_BUTTON_CONNECT'),
					'/mail/mailbox-list?CONNECTION_REQUESTS=Y&apply_filter=Y',
					Design::OutlineAccent2,
				),
			),
		);

		$imageUrl = UrlManager::getInstance()->getHostUrl()
			. '/bitrix/images/mail/integration/im/messenger/card/mailbox-connection-request.webp';

		$result = (new Builder($chat))
			->addCardBlock(
				title: (string)Loc::getMessage('MAIL_CONNECTION_REQUEST_CHAT_CARD_TITLE'),
				imageUrl: $imageUrl,
				text: $cardText,
				buttons: $buttons,
			)
			->setBackground(Background::Plain)
			->build()
		;

		$blocksBuilder = $result->getBlocksBuilder();
		if ($blocksBuilder === null || !$result->isSuccess())
		{
			$errorMessages = array_map(
				static fn ($error) => $error->getMessage(),
				$result->getErrors(),
			);

			throw new \RuntimeException(
				'Failed to build connection request card: ' . implode('; ', $errorMessages),
			);
		}

		return $blocksBuilder;
	}

	public function ensureAdminsInChat(int $chatId, array $adminIds): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if (empty($adminIds))
		{
			return;
		}

		$chat = Im\V2\Chat::getInstance($chatId);
		if ($chat === null)
		{
			return;
		}

		$currentUserIds = $chat->getRelations()->getUserIds();

		$missingAdminIds = array_diff($adminIds, $currentUserIds);

		if (empty($missingAdminIds))
		{
			return;
		}

		$chat->addUsers(
			array_values($missingAdminIds),
			new AddUsersConfig(hideHistory: false, withMessage: false),
		);
	}

	public function hasActiveAdminsInChat(int $chatId, array $adminIds): bool
	{
		if (!Loader::includeModule('im') || $chatId <= 0 || empty($adminIds))
		{
			return false;
		}

		$chat = Im\V2\Chat::getInstance($chatId);
		if ($chat === null)
		{
			return false;
		}

		$currentUserIds = $chat->getRelations()->getUserIds();

		return !empty(array_intersect($adminIds, $currentUserIds));
	}

	public function hasUserInChat(int $chatId, int $userId): bool
	{
		if (!Loader::includeModule('im') || $chatId <= 0 || $userId <= 0)
		{
			return false;
		}

		$chat = Im\V2\Chat::getInstance($chatId);
		if ($chat === null)
		{
			return false;
		}

		return in_array($userId, $chat->getRelations()->getUserIds(), true);
	}

	public function isValidRequestChat(int $chatId, int $requesterId, array $adminIds): bool
	{
		return $this->hasUserInChat($chatId, $requesterId)
			&& $this->hasActiveAdminsInChat($chatId, $adminIds)
		;
	}
	private function getUserFormattedName(int $userId): string
	{
		$user = UserTable::getList([
			'select' => ['NAME', 'LAST_NAME'],
			'filter' => ['=ID' => $userId],
			'limit' => 1,
		])->fetch();

		if (!$user)
		{
			return '';
		}

		return \CUser::FormatName(\CSite::GetNameFormat(), $user);
	}

	private function findReusableChatId(int $requesterId, array $adminIds): int
	{
		foreach ($this->getRequesterChatIds($requesterId) as $chatId)
		{
			if ($this->isValidRequestChat($chatId, $requesterId, $adminIds))
			{
				return $chatId;
			}
		}

		return 0;
	}

	/**
	 * @return int[]
	 */
	private function getRequesterChatIds(int $requesterId): array
	{
		if ($requesterId <= 0)
		{
			return [];
		}

		$chatIds = [];
		$chatRows = ChatTable::getList([
			'filter' => [
				'=ENTITY_TYPE' => self::ENTITY_TYPE,
				'=ENTITY_ID' => (string)$requesterId,
			],
			'select' => ['ID'],
		]);

		while ($chatRow = $chatRows->fetch())
		{
			$chatIds[] = (int)$chatRow['ID'];
		}

		return array_values(array_unique($chatIds));
	}
}
