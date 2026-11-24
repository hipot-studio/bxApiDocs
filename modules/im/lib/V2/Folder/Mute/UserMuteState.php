<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Mute;

/**
 * The rules of one user, resolved into the two shapes a rule can take (ALG-01): the chats of the
 * muted personal folders, and the recent sections of the muted system folders, each section carrying
 * the nesting frames it was muted from. A folder is not mentioned here at all - the question this
 * state answers is about a chat, and by the time it is asked it no longer matters which folder made
 * it silent.
 *
 * The frame travels with the section because a system folder holds a section constrained to one
 * nesting level ({@see \Bitrix\Im\V2\Folder\Definition\SectionDefinition::containsChat()}): a rule of
 * the root folder of a section leaves the chats of that section inside collabs sounding, and a rule
 * of a folder inside a collab leaves the root ones sounding.
 *
 * Immutable and cacheable: one state is read many times per request, and the empty one is the
 * common case, so {@see self::isEmpty()} is what every consumer asks first.
 */
final class UserMuteState
{
	/**
	 * Shape of {@see self::toCacheArray()} and of the rules it was built by. An entry written in any
	 * other format reads as a miss ({@see self::fromCacheArray()}) rather than as a state, so a release
	 * that changes the shape, or stops honouring a rule an entry may hold, rebuilds the entries instead
	 * of answering from them. Format 3: the rule of the open-line folder no longer applies
	 * ({@see MuteService::UNMUTABLE_FOLDER_CODES}).
	 */
	private const CACHE_FORMAT = 3;

	/** @var array<int, true> chat id => true */
	private readonly array $mutedChatIds;

	/** @var array<string, array<int, ?int>> recent section => nesting frames muting it, `null` = every level */
	private readonly array $mutedSections;

	/**
	 * @param int[] $mutedChatIds chats of the muted personal folders
	 * @param array<string, array<int, ?int>> $mutedSections section of a muted system folder => its
	 *     nesting frames ({@see \Bitrix\Im\V2\Folder\BaseFolder::getRecentParentScope()}): `null`
	 *     stands for every level, `0` for the root one
	 */
	public function __construct(array $mutedChatIds = [], array $mutedSections = [])
	{
		$this->mutedChatIds = array_fill_keys(array_map('intval', $mutedChatIds), true);
		$this->mutedSections = self::normalizeSections($mutedSections);
	}

	/**
	 * Whether a rule of the user silences this chat.
	 *
	 * @param string[] $recentSections sections the chat belongs to in the recent list
	 * @param int|null $parentChatId parent of the chat; a chat without one is a root chat
	 */
	public function covers(int $chatId, array $recentSections, ?int $parentChatId): bool
	{
		if ($this->isEmpty())
		{
			return false;
		}

		if (isset($this->mutedChatIds[$chatId]))
		{
			return true;
		}

		$parentChatId ??= 0;

		foreach ($recentSections as $section)
		{
			foreach ($this->mutedSections[(string)$section] ?? [] as $frame)
			{
				if ($frame === null || $frame === $parentChatId)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function isEmpty(): bool
	{
		return $this->mutedChatIds === [] && $this->mutedSections === [];
	}

	/**
	 * @return int[]
	 */
	public function getMutedChatIds(): array
	{
		return array_keys($this->mutedChatIds);
	}

	/**
	 * @return array<string, array<int, ?int>> section => its nesting frames, never an empty list
	 */
	public function getMutedSections(): array
	{
		return $this->mutedSections;
	}

	/**
	 * @internal Used only by {@see MuteCache}.
	 * @return array{format: int, mutedChatIds: int[], mutedSections: array<string, array<int, ?int>>}
	 */
	public function toCacheArray(): array
	{
		return [
			'format' => self::CACHE_FORMAT,
			'mutedChatIds' => $this->getMutedChatIds(),
			'mutedSections' => $this->mutedSections,
		];
	}

	/**
	 * @internal Used only by {@see MuteCache}.
	 * @param array{format?: mixed, mutedChatIds?: mixed, mutedSections?: mixed} $data
	 * @return self|null null for an entry this version cannot read, which the cache answers as a miss
	 */
	public static function fromCacheArray(array $data): ?self
	{
		if (
			($data['format'] ?? null) !== self::CACHE_FORMAT
			|| !is_array($data['mutedChatIds'] ?? null)
			|| !is_array($data['mutedSections'] ?? null)
		)
		{
			return null;
		}

		return new self($data['mutedChatIds'], $data['mutedSections']);
	}

	/**
	 * A folder showing every level leaves nothing for the other frames of its section to add, so it
	 * collapses the list: the consumers then read one frame instead of walking a set that cannot
	 * change the answer. A section carrying no frame at all is dropped rather than kept as an empty
	 * list - that shape is one {@see self::covers()} and the query filter would both have to guard
	 * against.
	 *
	 * @param array<string, array<int, ?int>> $sections
	 * @return array<string, array<int, ?int>>
	 */
	private static function normalizeSections(array $sections): array
	{
		$normalized = [];

		foreach ($sections as $section => $frames)
		{
			if (!is_array($frames))
			{
				continue;
			}

			$parents = [];
			foreach ($frames as $frame)
			{
				if ($frame === null)
				{
					$parents = [null];

					break;
				}

				$parents[(int)$frame] = (int)$frame;
			}

			if ($parents !== [])
			{
				$normalized[(string)$section] = array_values($parents);
			}
		}

		return $normalized;
	}
}
