<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserFactory;
use Bitrix\Main\Loader;

/**
 * @internal
 */

final class MentionService
{
	public const
		FORMAT_BB = 'bb',
		FORMAT_HTML = 'html',
		FORMAT_NONE = 'none';

	private const BB_MENTION_PATTERN = "#\[user=([0-9]+)\](.+?)\[/user\]#iu";

	private static ?MentionService $service = null;

	private array $mentionsAi = [];
	private array $hashAi = [];
	private array $searchAi = [];
	private array $mentionsBb = [];
	private array $searchBb = [];
	private array $mentionsPage = [];
	private array $userName = [];
	private array $userAiName = [];

	private function __construct()
	{}

	public static function getInstance(): self
	{
		if (!self::$service)
		{
			self::$service = new self();
		}
		return self::$service;
	}

	public function removeBBMentions(?string $text): ?string
	{
		return $this->renderBBMentions($text, fn (int $userId): string => $this->getUserName($userId));
	}

	public function replaceBBMentions(?string $text): ?string
	{
		return $this->renderBBMentions($text, fn (int $userId): string => $this->getPageMention($userId));
	}

	/**
	 * BB mentions carry the user id, so they are rendered from the text alone and the mention
	 * dictionary of a call is not needed. That keeps a batch read over many calls from loading every
	 * call, its participants and its associated entity just to render them. Names resolved here are
	 * cached for the whole request, so a user repeated across calls costs one lookup.
	 *
	 * A user that cannot be resolved keeps the label stored next to the mention.
	 */
	private function renderBBMentions(?string $text, callable $render): ?string
	{
		if (!$text)
		{
			return $text;
		}

		return preg_replace_callback(
			self::BB_MENTION_PATTERN,
			function (array $matches) use ($render): string {
				$userId = (int)$matches[1];

				return $this->getUserName($userId) === '' ? $matches[2] : (string)$render($userId);
			},
			$text,
		);
	}

	public function replaceAiMentions(?string $text): ?string
	{
		if ($text)
		{
			foreach ($this->searchAi as $userId => $regExp)
			{
				$text = preg_replace("#{$regExp}#iu", $this->getBbMention($userId), $text);
			}
			foreach ($this->hashAi as $hash => $userId)
			{
				$userName = $this->getAIUserName($userId);
				$text = preg_replace("#@?{$userName}\s*_{$hash}#iu", $this->getBbMention($userId), $text);
			}
			if (preg_match_all("#@?([[:alnum:]]+)\s*_\s*([0-9]+)#iu", $text, $mentions))
			{
				foreach ($mentions[0] as $i => $_)
				{
					if ($userId = $this->hashAi[(int)$mentions[2][$i]])
					{
						$text = str_replace($mentions[0][$i], $this->getBbMention($userId), $text);
					}
				}
			}
		}

		return $text;
	}

	public function detectUserIdByBBMentions(?string $text): ?int
	{
		if ($text)
		{
			foreach ($this->searchBb as $userId => $regExp)
			{
				if (preg_match("#{$regExp}#iu", $text))
				{
					return $userId;
				}
			}
			if (preg_match(self::BB_MENTION_PATTERN, $text, $matches))
			{
				if (isset($this->searchBb[(int)$matches[1]]))
				{
					return (int)$matches[1];
				}
			}
		}

		return null;
	}

	public function detectUserIdByAiMentions(?string $text): ?int
	{
		if ($text)
		{
			foreach ($this->searchAi as $userId => $regExp)
			{
				if (preg_match("#{$regExp}#iu", $text))
				{
					return $userId;
				}
			}
			foreach ($this->hashAi as $hash => $userId)
			{
				$userName = $this->getAIUserName($userId);
				if (preg_match("#@?{$userName}\s*_{$hash}#iu",  $text))
				{
					return $userId;
				}
			}
			if (preg_match("#@?([[:alnum:]]+)\s*_\s*([0-9]+)#iu", $text, $mentions))
			{
				if (isset($this->hashAi[(int)$mentions[1]]))
				{
					return (int)$mentions[1];
				}
			}
		}

		return null;
	}

	public function loadMentionsForCall(int $callId): void
	{
		$call = \Bitrix\Call\Call\Registry::getCallWithId($callId);
		$users = array_unique(array_merge(
			$call->getUsers(),
			$call->getAssociatedEntity()?->getUsers() ?? []
		));
		foreach ($users as $userId)
		{
			$hash = $this->generateShortId($callId, $userId);
			$this->hashAi[$hash] = $userId;
			$userName = $this->getAIUserName($userId);
			$this->searchAi[$userId] = "@?" . preg_quote($userName, '#') . "\s*_\s*({$hash})";
			$this->searchBb[$userId] = "\[user={$userId}\](.+?)\[/user\]";

			$this->getBbMention($userId);
			$this->getAIMention($userId, $callId);
			$this->getPageMention($userId);
		}
	}

	/**
	 * Resolves the names mentioned across a batch of texts in one query, so rendering many texts does
	 * not read one user per mention. Ids already resolved in this request are skipped by the user cache.
	 *
	 * @param iterable<mixed> $values Texts, or content objects holding them.
	 */
	public function preloadMentionedUsers(iterable $values): void
	{
		$userIds = [];
		$this->collectMentionedUserIds($values, $userIds);
		$userIds = array_values(array_diff(array_keys($userIds), array_keys($this->userName)));
		if ($userIds === [])
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		// Names are stored right away: an id absent from the database is resolved by the batch too, so
		// rendering reads no user of its own.
		foreach (UserFactory::getInstance()->getUsersByIds($userIds) as $userId => $user)
		{
			$this->userName[$userId] = $user->isExist() ? trim((string)$user->getName()) : '';
		}
	}

	/**
	 * @param array<int, true> $userIds Collected ids, by reference to keep one set for the whole batch.
	 */
	private function collectMentionedUserIds(mixed $value, array &$userIds): void
	{
		if (is_string($value))
		{
			if (preg_match_all(self::BB_MENTION_PATTERN, $value, $matches))
			{
				foreach ($matches[1] as $userId)
				{
					$userIds[(int)$userId] = true;
				}
			}

			return;
		}

		if (is_object($value) && !is_iterable($value))
		{
			$value = get_object_vars($value);
		}

		if (is_iterable($value))
		{
			foreach ($value as $item)
			{
				$this->collectMentionedUserIds($item, $userIds);
			}
		}
	}

	public function getUserName(int $userId): string
	{
		if (!isset($this->userName[$userId]))
		{
			$user = $this->imUser($userId);
			// An unknown id keeps the label stored next to the mention instead of an anonymous name.
			$this->userName[$userId] = $user?->isExist() ? trim((string)$user->getName()) : '';
		}
		return $this->userName[$userId];
	}

	/**
	 * Names live in im, and the autoloader resolves its V2 namespace only for an included module, so
	 * the dependency is stated here instead of relying on the caller's context.
	 */
	private function imUser(int $userId): ?User
	{
		return Loader::includeModule('im') ? User::getInstance($userId) : null;
	}

	public function getAIUserName(int $userId): string
	{
		if (!isset($this->userAiName[$userId]))
		{
			$userName = trim((string)$this->imUser($userId)?->getFirstName());
			$userName = preg_replace("#\s+#", '_', $userName);
			$userName = preg_replace("#[^\p{L}\p{N}_]#u", '', $userName);
			$this->userAiName[$userId] = $userName ?: "User";
		}
		return $this->userAiName[$userId];
	}

	public function getBbMention(int $userId): string
	{
		if (!isset($this->mentionsBb[$userId]))
		{
			$userName = $this->getUserName($userId);
			$this->mentionsBb[$userId] = "[user={$userId}]{$userName}[/user]";
		}
		return $this->mentionsBb[$userId];
	}

	public function getAIMention(int $userId, int $callId): string
	{
		if (!isset($this->mentionsAi[$userId]))
		{
			$userName = $this->getAIUserName($userId);
			$hash = $this->generateShortId($callId, $userId);
			$this->mentionsAi[$userId] = "{$userName}_{$hash}";
		}
		return $this->mentionsAi[$userId];
	}

	public function getPageMention(int $userId, string $tag = ''): string
	{
		$tag = $tag ?: "<span class=\"bx-call-mention\" bx-tooltip-user-id=\"%d\">%s</span>";
		if (!isset($this->mentionsPage[$userId]))
		{
			$userName = htmlspecialcharsbx($this->getUserName($userId));
			$this->mentionsPage[$userId] = sprintf($tag, $userId, $userName);
		}
		return $this->mentionsPage[$userId];
	}

	protected function generateShortId(int $callId, int $userId): int
	{
		$number = (string)($callId + $userId);
		$sum = 0;
		$flag = 0;
		for ($i = strlen($number) - 1; $i >= 0; $i--)
		{
			$add = $flag++ & 1 ? $number[$i] * 2 : $number[$i];
			$sum += $add > 9 ? $add - 9 : $add;
		}

		return $number. $sum;
	}
}
