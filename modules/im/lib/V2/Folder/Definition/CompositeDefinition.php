<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;

/**
 * Composite folder ("All"): defined by the codes of its member folders. Its
 * selection is the union of the members' branches - one {@see SelectionBranch}
 * per source, resolved by looking each code up in the member registry
 * (source code == member folder code) and reading the member's recent section and
 * root frame. Sources unavailable to the owner are dropped at resolve time, so the
 * stored composition stays complete and enabling a source needs no rewrite.
 *
 * The REST shape is the composition the client can act on as is: the same resolved
 * members, each under the rest code its own folder is published with
 * ({@see SystemFolder::getRestCode()}), so a source is matched against the folder list
 * by plain code equality and no availability filter is left for the client to repeat.
 * Without an owner the shape stays the raw stored code list - see the constructor for
 * the paths that need it.
 */
final class CompositeDefinition implements FolderDefinition
{
	/** @var string[] */
	private readonly array $sourceCodes;
	private readonly ?int $userId;
	private readonly SystemFolderRegistry $registry;

	/** @var SystemFolder[]|null resolved members, memoized on first use */
	private ?array $members = null;

	/**
	 * @param string[] $sourceCodes member folder codes, e.g. ['chat', 'channel', 'tasksTaskRoot']
	 * @param int|null $userId owner the composition is resolved for; null resolves the stored
	 *        composition as is, without the per-user availability filter - that is what the write
	 *        paths need (bootstrap must create rows, composition and pin shadows regardless of the
	 *        release gate, and shadow garbage collection must still see a source it just lost)
	 * @param SystemFolderRegistry|null $registry member resolver; defaults to a fresh, stateless registry
	 */
	public function __construct(array $sourceCodes, ?int $userId = null, ?SystemFolderRegistry $registry = null)
	{
		$this->sourceCodes = array_values($sourceCodes);
		$this->userId = $userId;
		$this->registry = $registry ?? new SystemFolderRegistry();
	}

	public function toRestFormat(): array
	{
		if ($this->userId === null)
		{
			return ['sourceFolders' => $this->sourceCodes];
		}

		$restCodes = [];
		foreach ($this->resolveMembers() as $member)
		{
			$restCode = $member->getRestCode();
			if ($restCode === null)
			{
				continue;
			}

			$restCodes[] = $restCode;
		}

		return ['sourceFolders' => $restCodes];
	}

	public function toSelection(): Selection
	{
		$branches = [];
		foreach ($this->resolveMembers() as $member)
		{
			$section = $member->getRecentSection();
			if ($section === null)
			{
				continue;
			}

			$branches[] = new SelectionBranch(
				typeCondition: $section,
				parentChatId: $member->getRecentParentScope(),
				chatIds: null,
			);
		}

		return new Selection(...$branches);
	}

	public function supportsTail(): bool
	{
		return true;
	}

	/**
	 * Member of "All" iff the chat belongs to any enabled source - each source's
	 * own definition answers membership (recent section + root frame), so this
	 * agrees with {@see self::toSelection()} / {@see SelectionResolver::matchingChatIds()}.
	 */
	public function containsChat(int $chatId): bool
	{
		foreach ($this->resolveMembers() as $member)
		{
			if ($member->getDefinition()->containsChat($chatId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Enabled source codes resolved to their member folders (source code ==
	 * member folder code); unknown codes and codes unavailable to the owner are
	 * skipped. Single point of member resolution shared by {@see self::toRestFormat()},
	 * {@see self::toSelection()} and {@see self::containsChat()}.
	 *
	 * Memoized: the object is immutable, while containsChat() runs in loops (legacy
	 * pin migration walks every pinned chat, the shadow fan-out runs on every pin).
	 *
	 * @return SystemFolder[]
	 */
	private function resolveMembers(): array
	{
		if ($this->members !== null)
		{
			return $this->members;
		}

		$members = [];
		foreach ($this->sourceCodes as $code)
		{
			$member = $this->registry->findByCode($code)?->instantiate();
			if ($member === null)
			{
				continue;
			}

			if ($this->userId !== null && !$member->isAvailable($this->userId))
			{
				continue;
			}

			$members[] = $member;
		}

		$this->members = $members;

		return $this->members;
	}
}
