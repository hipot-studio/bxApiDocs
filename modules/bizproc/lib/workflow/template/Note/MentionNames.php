<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Note;

use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;

/**
 * The actual names of the users mentioned in a set of message texts: DTO-03 keeps the name a mention was
 * written with in the store and overwrites it with the actual one on the output, so a renamed employee is
 * read by their current name and a signature bound to a foreign id does not survive the answer.
 *
 * Built for the whole set at once and never per message: a conversation travels to the client in one
 * answer, and a name read message by message would cost a query per message.
 *
 * The format is the one the hint of the mentions showed while the name was being picked
 * ({@see UserProvider::formatUserName()}), otherwise a saved mention would visibly move to another format
 * of the name. That hint is the only way to write a mention and it lives in socialnetwork, so without that
 * module there is nothing to resolve a name by and the text travels as it was written.
 */
final class MentionNames
{
	/** @var array<int, string> */
	private array $names;

	/**
	 * @param array<int, string> $names
	 */
	private function __construct(array $names)
	{
		$this->names = $names;
	}

	/**
	 * @param string[] $texts
	 */
	public static function forTexts(array $texts): self
	{
		$userIds = [];
		foreach ($texts as $text)
		{
			foreach (MessageText::extractMentions($text) as $userId)
			{
				$userIds[$userId] = $userId;
			}
		}

		return new self($userIds ? self::loadNames(array_values($userIds)) : []);
	}

	public static function forText(string $text): self
	{
		return self::forTexts([$text]);
	}

	public function apply(string $text): string
	{
		return MessageText::rewriteMentionNames($text, $this->names);
	}

	/**
	 * One query for the whole set. A user who is gone from the portal has no row here and their mention
	 * keeps the name the message was written with: the tag stays whole, so the client reads it exactly as
	 * before and the reader still sees who was meant.
	 *
	 * @param int[] $userIds
	 * @return array<int, string>
	 */
	private static function loadNames(array $userIds): array
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$users = UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL', 'TITLE'])
			->whereIn('ID', $userIds)
			->fetchCollection()
		;

		$names = [];
		foreach ($users as $user)
		{
			$name = UserProvider::formatUserName($user);
			if ($name !== '')
			{
				$names[(int)$user->getId()] = $name;
			}
		}

		return $names;
	}
}
