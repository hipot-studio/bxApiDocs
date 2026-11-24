<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Mute;

use Bitrix\Im\Model\FolderChatTable;
use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Entity\User\UserFactory;
use Bitrix\Im\V2\Folder\Definition\SectionDefinition;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\FolderFactory;
use Bitrix\Im\V2\Folder\PersonalFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * The single source of the answer "does a rule of this user silence this chat" (ALG-01): every
 * consumer - both processed views of the counters, the filter of the unread-only mode and the
 * mobile push - reads the same state from here and none of them looks at the folders itself.
 *
 * Read across every nesting scope at once, unlike the folder list, which is read per scope: a rule
 * of a folder inside a collab silences its chats just as a rule of a root folder does.
 */
class MuteProvider
{
	public function __construct(
		private readonly MuteCache $muteCache,
		private readonly FolderFactory $folderFactory,
		private readonly SystemFolderRegistry $systemFolderRegistry,
	) {}

	public function getForUser(int $userId): UserMuteState
	{
		if ($userId <= 0)
		{
			return new UserMuteState();
		}

		return $this->getForUsers([$userId])[$userId] ?? new UserMuteState();
	}

	/**
	 * The states of several users, with one query for everyone the cache does not answer for - the
	 * push of a message asks about every recipient of a chat at once.
	 *
	 * @param int[] $userIds
	 * @return array<int, UserMuteState> userId => state, one entry per requested user
	 */
	public function getForUsers(array $userIds): array
	{
		$userIds = Normalizer::toUniquePositiveIntegers($userIds);
		if ($userIds === [])
		{
			return [];
		}

		// A portal with the feature off has no rules to apply, so the answer costs neither the cache
		// nor the query. That the rows written while it was on stop silencing anything is the point of
		// the branch and not its side effect: taking such a rule off by hand is closed by the same flag
		// ({@see MuteService::setMuted()}).
		if (!Features::isFolderMuteAvailable())
		{
			return array_fill_keys($userIds, new UserMuteState());
		}

		$states = [];
		$missing = [];
		foreach ($userIds as $userId)
		{
			$cached = $this->muteCache->get($userId);
			if ($cached === null)
			{
				$missing[] = $userId;
			}
			else
			{
				$states[$userId] = $cached;
			}
		}

		if ($missing === [])
		{
			return $states;
		}

		$loaded = $this->loadForUsers($missing);
		foreach ($missing as $userId)
		{
			$state = $loaded[$userId] ?? new UserMuteState();
			// The empty state is stored too: a user with no rules is the common case, and leaving it
			// uncached would make every request pay the query above.
			$this->muteCache->set($userId, $state);
			$states[$userId] = $state;
		}

		return $states;
	}

	/**
	 * Which of these recipients have a rule silencing this chat - the answer
	 * {@see UserMuteState::covers()} gives over {@see self::getForUsers()}, read for this one chat
	 * instead of building the whole state of every recipient. The send of a message asks about every
	 * member at once, and the full state costs a cache read per member and every muted folder of each.
	 *
	 * One query whatever the number of recipients: the muted personal folders holding the chat, and
	 * the muted system folders of the members whose section and frame reach it. The cache is neither
	 * read nor written - the recent list and the counters fill it with their own reads.
	 *
	 * The members are found through the relations of the chat, so a recipient who is not a member of
	 * it is never covered; the push asks only about members.
	 *
	 * @param int[] $userIds
	 * @param string[] $recentSections sections of the chat in the recent list ({@see \Bitrix\Im\V2\Chat::getRecentSections()})
	 * @param int|null $parentChatId parent of the chat ({@see \Bitrix\Im\V2\Chat::getParentChatId()})
	 * @return int[] ids of the recipients the rules cover
	 */
	public function getUsersCoveredByRule(
		array $userIds,
		int $chatId,
		array $recentSections,
		?int $parentChatId,
	): array
	{
		$userIds = Normalizer::toUniquePositiveIntegers($userIds);
		if ($chatId <= 0 || $userIds === [] || !Features::isFolderMuteAvailable())
		{
			return [];
		}

		$rows = $this->queryRulesOfChat(
			$chatId,
			$this->collectCodesReachingChat($chatId, $recentSections, $parentChatId),
		);
		if ($rows === [])
		{
			return [];
		}

		$recipients = array_fill_keys($userIds, true);
		$rows = array_filter($rows, static fn (array $row): bool => isset($recipients[(int)$row['USER_ID']]));
		$this->warmUpUsersOfSystemFolders($rows);

		$covered = [];
		foreach ($rows as $row)
		{
			$userId = (int)$row['USER_ID'];
			if (isset($covered[$userId]))
			{
				continue;
			}

			$folder = $this->createRuleFolder($row);
			$state = match (true)
			{
				$folder instanceof PersonalFolder => new UserMuteState([$chatId]),
				$folder instanceof SystemFolder => $this->createSectionState($this->readRuleOfUser($folder, $userId)),
				default => null,
			};

			if ($state?->covers($chatId, $recentSections, $parentChatId))
			{
				$covered[$userId] = true;
			}
		}

		return array_keys($covered);
	}

	/**
	 * The codes of the system folders whose rule reaches this chat, by the stored parent of the folder
	 * row. A row carries its parent as the frame ({@see \Bitrix\Im\V2\Folder\BaseFolder::getRecentParentScope()}),
	 * or every level when it is a root row of a folder showing the nested chats, so only rows at the
	 * parent of the chat or at the root can reach it. Each code is asked of the same rule the state is
	 * built by - the folder is created from a row as the reader creates it - so the query cannot
	 * disagree with {@see UserMuteState::covers()} over which folders count.
	 *
	 * @param string[] $recentSections
	 * @return array<int, string[]> stored parent of the folder row => codes
	 */
	private function collectCodesReachingChat(int $chatId, array $recentSections, ?int $parentChatId): array
	{
		$frames = array_unique([(int)($parentChatId ?? 0), 0]);

		$codesByParent = [];
		foreach ($this->systemFolderRegistry->getAll() as $prototype)
		{
			foreach ($frames as $frame)
			{
				$folder = $this->createRuleFolder([
					'TYPE' => SystemFolder::TYPE,
					'CODE' => $prototype->code,
					'PARENT_CHAT_ID' => $frame,
				]);
				$state = $folder instanceof SystemFolder ? $this->createSectionState($this->readRule($folder)) : null;
				if ($state?->covers($chatId, $recentSections, $parentChatId))
				{
					$codesByParent[$frame][] = $prototype->code;
				}
			}
		}

		return $codesByParent;
	}

	/**
	 * The state one system rule makes on its own: the answer about a chat is then given by
	 * {@see UserMuteState::covers()} and by nothing written for this path.
	 *
	 * @param array{0: string, 1: ?int}|null $rule
	 */
	private function createSectionState(?array $rule): ?UserMuteState
	{
		return $rule !== null ? new UserMuteState([], [$rule[0] => [$rule[1]]]) : null;
	}

	/**
	 * Muted folder rows that may silence this chat for one of its members. The personal branch reads
	 * the composition by the chat; each system branch walks the members of the chat and reaches their
	 * folders by (USER_ID, PARENT_CHAT_ID, CODE), so neither of them reads the other folders of a
	 * recipient.
	 *
	 * @param array<int, string[]> $codesByParent
	 * @return array<int, array{ID: int, USER_ID: int, PARENT_CHAT_ID: int, TYPE: string, CODE: ?string}>
	 */
	private function queryRulesOfChat(int $chatId, array $codesByParent): array
	{
		$query = FolderChatTable::query()
			->setSelect([
				'RULE_ID' => 'FOLDER.ID',
				'RULE_USER_ID' => 'FOLDER.USER_ID',
				'RULE_PARENT_CHAT_ID' => 'FOLDER.PARENT_CHAT_ID',
				'RULE_TYPE' => 'FOLDER.TYPE',
				'RULE_CODE' => 'FOLDER.CODE',
			])
			->where('CHAT_ID', $chatId)
			->where('FOLDER.TYPE', PersonalFolder::TYPE)
			->where('FOLDER.NOTIFY_BLOCK', true)
		;

		foreach ($codesByParent as $parentId => $codes)
		{
			$query->unionAll($this->buildSystemBranch($chatId, (int)$parentId, $codes));
		}

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$rows[] = [
				'ID' => (int)$row['RULE_ID'],
				'USER_ID' => (int)$row['RULE_USER_ID'],
				'PARENT_CHAT_ID' => (int)$row['RULE_PARENT_CHAT_ID'],
				'TYPE' => (string)$row['RULE_TYPE'],
				'CODE' => $row['RULE_CODE'] !== null ? (string)$row['RULE_CODE'] : null,
			];
		}

		return $rows;
	}

	/**
	 * @param string[] $codes
	 */
	private function buildSystemBranch(int $chatId, int $parentId, array $codes): Query
	{
		return FolderTable::query()
			->registerRuntimeField(
				(new Reference('MEMBER', RelationTable::class, Join::on('this.USER_ID', 'ref.USER_ID')))
					->configureJoinType(Join::TYPE_INNER)
			)
			->setSelect([
				'RULE_ID' => 'ID',
				'RULE_USER_ID' => 'USER_ID',
				'RULE_PARENT_CHAT_ID' => 'PARENT_CHAT_ID',
				'RULE_TYPE' => 'TYPE',
				'RULE_CODE' => 'CODE',
			])
			->where('MEMBER.CHAT_ID', $chatId)
			->where('PARENT_CHAT_ID', $parentId)
			->whereIn('CODE', $codes)
			->where('TYPE', SystemFolder::TYPE)
			->where('NOTIFY_BLOCK', true)
		;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, UserMuteState>
	 */
	private function loadForUsers(array $userIds): array
	{
		$rows = FolderTable::query()
			->setSelect(['*'])
			->whereIn('USER_ID', $userIds)
			->where('NOTIFY_BLOCK', true)
			->fetchAll()
		;

		if ($rows === [])
		{
			return [];
		}

		$this->warmUpUsersOfSystemFolders($rows);

		$sectionsByUser = [];
		$personalFolderOwners = [];

		foreach ($rows as $row)
		{
			$userId = (int)$row['USER_ID'];
			$folder = $this->createRuleFolder($row);

			if ($folder instanceof PersonalFolder)
			{
				$personalFolderOwners[(int)$row['ID']] = $userId;

				continue;
			}

			$rule = $folder instanceof SystemFolder ? $this->readRuleOfUser($folder, $userId) : null;
			if ($rule !== null)
			{
				$sectionsByUser[$userId][$rule[0]][] = $rule[1];
			}
		}

		$chatIdsByUser = $this->loadChatIds($personalFolderOwners);

		$states = [];
		foreach ($userIds as $userId)
		{
			$states[$userId] = new UserMuteState(
				$chatIdsByUser[$userId] ?? [],
				$sectionsByUser[$userId] ?? [],
			);
		}

		return $states;
	}

	/**
	 * The folder of a stored row as the rules see it. A folder the write path refuses a rule
	 * ({@see MuteService::UNMUTABLE_FOLDER_CODES}) is ignored here, and a row that got one anyway -
	 * written past the service or before the refusal - silences nothing. Every read goes through
	 * this method, the state as well as the rule of one chat, so the two cannot disagree over it.
	 */
	private function createRuleFolder(array $row): ?Folder
	{
		$folder = $this->folderFactory->createFromRow($row);
		if ($folder === null || in_array($folder->getCode(), MuteService::UNMUTABLE_FOLDER_CODES, true))
		{
			return null;
		}

		return $folder;
	}

	/**
	 * The rule of a muted system folder for its owner: a folder the user cannot reach rules nothing.
	 *
	 * @return array{0: string, 1: ?int}|null section, nesting frame
	 */
	private function readRuleOfUser(SystemFolder $folder, int $userId): ?array
	{
		$rule = $this->readRule($folder);

		return $rule !== null && $folder->isAvailable($userId) ? $rule : null;
	}

	/**
	 * The part of the rule of a system folder that does not depend on its owner.
	 *
	 * @return array{0: string, 1: ?int}|null section, nesting frame
	 */
	private function readRule(SystemFolder $folder): ?array
	{
		// A folder the system deliberately keeps out of the UI rules nothing. In particular, an old
		// NOTIFY_BLOCK on Default survives the transition to "All", but must not keep silencing chats
		// after Default becomes hidden.
		if ($folder->isHiddenBySystem())
		{
			return null;
		}

		// The composition is asked of the folder and not assembled from its section here. The two
		// answers differ for a folder whose list is not a section of the recent one: the open-channel
		// showcase carries a section name but an
		// {@see \Bitrix\Im\V2\Folder\Definition\UnsupportedDefinition}, its list is built from the
		// chat table and holds nothing of the user's recent rows. Reading its section would write a
		// rule silencing every open channel through a folder that contains no chat at all.
		$definition = $folder->getDefinition();
		if (!$definition instanceof SectionDefinition)
		{
			return null;
		}

		$section = $definition->getRecentSection();
		if ($section === null || $section === '')
		{
			return null;
		}

		// The nesting frame goes with the section and is not dropped: a folder holds the chats of its
		// section that sit at its own level and no others ({@see SectionDefinition::containsChat()}),
		// so a section without its frame would silence chats the folder does not contain.
		return [$section, $definition->getParentChatId()];
	}

	/**
	 * Whether a system folder is available is a question about the user
	 * ({@see SystemFolder::isAvailable()}), asked once per row in the loop above. The users behind
	 * those rows are read here in one query, so every question there meets a warm cache instead of a
	 * read of its own. Only they are read: the requested set is every recipient of a chat, and a
	 * recipient whose rules are all personal is never asked about.
	 *
	 * @param array<int, array> $rows
	 */
	private function warmUpUsersOfSystemFolders(array $rows): void
	{
		$userIds = [];
		foreach ($rows as $row)
		{
			if ((string)($row['TYPE'] ?? '') === SystemFolder::TYPE)
			{
				$userIds[] = (int)$row['USER_ID'];
			}
		}

		if ($userIds !== [])
		{
			UserFactory::getInstance()->getUsersByIds($userIds);
		}
	}

	/**
	 * @param array<int, int> $personalFolderOwners folderId => userId
	 * @return array<int, int[]> userId => chat ids
	 */
	private function loadChatIds(array $personalFolderOwners): array
	{
		if ($personalFolderOwners === [])
		{
			return [];
		}

		$rows = FolderChatTable::query()
			->setSelect(['FOLDER_ID', 'CHAT_ID'])
			->whereIn('FOLDER_ID', array_keys($personalFolderOwners))
			->fetchAll()
		;

		$chatIdsByUser = [];
		foreach ($rows as $row)
		{
			$userId = $personalFolderOwners[(int)$row['FOLDER_ID']] ?? null;
			if ($userId !== null)
			{
				$chatIdsByUser[$userId][] = (int)$row['CHAT_ID'];
			}
		}

		return $chatIdsByUser;
	}
}
