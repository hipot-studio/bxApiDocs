<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AI\UserKeyConverter;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class ParticipantResolver
{
	public function resolveResponsible(string $participantIdentifier, Chat $chat, int $authorId): ?User
	{
		if (!Loader::includeModule('tasks'))
		{
			return null;
		}

		if (empty($participantIdentifier))
		{
			return $this->getDefaultResponsible($chat, $authorId);
		}

		return $this->resolve($participantIdentifier, $chat) ?? $this->getDefaultResponsible($chat, $authorId);
	}

	/**
	 * @param string[] $participantIdentifiers
	 *
	 * @return UserCollection
	 */
	public function resolveAccomplices(array $participantIdentifiers, Chat $chat): UserCollection
	{
		$accompliceCollection = new UserCollection();

		if (!Loader::includeModule('tasks'))
		{
			return $accompliceCollection;
		}

		foreach ($participantIdentifiers as $participantIdentifier)
		{
			$accomplice = $this->resolve($participantIdentifier, $chat);

			if ($accomplice === null)
			{
				continue;
			}

			$accompliceCollection->add($accomplice);
		}

		return $accompliceCollection;
	}

	private function getDefaultResponsible(Chat $chat, int $authorId): User
	{
		if ($chat instanceof Chat\PrivateChat && !$chat->getCompanion()->isBot())
		{
			$companionId = $chat->getCompanionId();

			return new User($companionId);
		}

		return new User($authorId);
	}

	private function resolve(string $participantIdentifier, Chat $chat): ?User
	{
		$chatId = (int)$chat->getId();
		if ($chatId <= 0)
		{
			return null;
		}

		$userKeyConverter = new UserKeyConverter($chatId);

		$userId = (int)$userKeyConverter->convertToUserId($participantIdentifier);
		if ($userId <= 0)
		{
			return null;
		}

		return new User($userId);
	}
}
