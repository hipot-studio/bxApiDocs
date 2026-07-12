<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Common\Normalizer;

class ResolveRecentFixedChatIdsEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, ?string $recentSection, int $userId)
	{
		$parameters = [
			'recentSection' => $recentSection,
			'userId' => $userId,
		];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'ResolveRecentFixedChatIds';
	}

	public function getRecentSection(): ?string
	{
		return $this->parameters['recentSection'];
	}

	public function getUserId(): int
	{
		return $this->parameters['userId'];
	}

	/**
	 * @return int[]
	 */
	public function getRecentFixedChatIds(): array
	{
		$ids = [];
		foreach ($this->getResults() as $result)
		{
			$resultIds = $result->getParameters()['recentFixedChatIds'] ?? null;
			if (is_array($resultIds))
			{
				array_push($ids, ...$resultIds);
			}
		}

		return Normalizer::toUniquePositiveIntegers($ids);
	}
}
