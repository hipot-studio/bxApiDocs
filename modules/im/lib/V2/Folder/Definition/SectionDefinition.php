<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Main\DI\ServiceLocator;

/**
 * System-folder composition: a single recent section constrained to a nesting
 * frame. Membership is answered by the chat entity, which owns its recent section
 * data, plus the sub-type exclusions of the section ({@see self::containsChat()}).
 */
final class SectionDefinition implements FolderDefinition
{
	public function __construct(
		private readonly ?string $recentSection,
		private readonly ?int $parentChatId,
	) {}

	public function toRestFormat(): array
	{
		return [
			'recentSection' => $this->recentSection,
			'parentChatId' => $this->parentChatId,
		];
	}

	public function toSelection(): Selection
	{
		return new Selection(new SelectionBranch(
			typeCondition: $this->recentSection,
			parentChatId: $this->parentChatId,
			chatIds: null,
		));
	}

	public function supportsTail(): bool
	{
		return true;
	}

	/**
	 * Predicate twin of the section branch in
	 * {@see \Bitrix\Im\V2\Recent\Query\RecentFilter::applyBranchFilter()} and built branch part by
	 * branch part: section membership, the exclude half read from its single owner
	 * ({@see TypeRegistry::isTypeExcludedFromSection()}), the nesting frame. A dynamic entity type of
	 * the 'C' or 'O' literal carries its section the same way a registered pair does, so the query and
	 * this predicate agree on such a chat without a reservation.
	 */
	public function containsChat(int $chatId): bool
	{
		$section = $this->recentSection;
		$chat = Chat::getInstance($chatId);

		if ($section === null || !in_array($section, $chat->getRecentSections(), true))
		{
			return false;
		}

		if ($this->isExcludedFromSection($section, $chat))
		{
			return false;
		}

		return $this->parentChatId === null || $this->parentChatId === (int)($chat->getParentChatId() ?? 0);
	}

	private function isExcludedFromSection(string $section, Chat $chat): bool
	{
		return ServiceLocator::getInstance()
			->get(TypeRegistry::class)
			->isTypeExcludedFromSection($chat->getChatType(), $section)
		;
	}
}
