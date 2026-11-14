<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Member;

use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Public\Command\AbstractCommand;
use Bitrix\Im\V2\Result;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\ValidationResult;

final class AddMembersCommand extends AbstractCommand
{
	private readonly array $userIds;
	private readonly array $mutedUserIds;

	/**
	 * @param int[] $userIds users to become members; a hidden relation is revealed as a new member,
	 * 	a member who is already in the chat only gets the missing recent item, so pass members only to finish their adding
	 * @param int[] $mutedUserIds new members created or revealed with notifications turned off, a subset of $userIds
	 * @param bool $addToRecent creates recent items of the new members and of the passed members without one, without a system message
	 * @param int|null $unreadMarkedMessageId message of the chat the created recent items are marked unread at, requires $addToRecent
	 */
	public function __construct(
		private readonly int $chatId,
		array $userIds,
		array $mutedUserIds = [],
		private readonly bool $addToRecent = false,
		private readonly ?int $unreadMarkedMessageId = null,
		private readonly bool $withMessage = false,
		private readonly bool $hideHistory = false,
	)
	{
		$this->userIds = Normalizer::toUniquePositiveIntegers($userIds);
		$this->mutedUserIds = array_values(array_intersect(
			Normalizer::toUniquePositiveIntegers($mutedUserIds),
			$this->userIds,
		));
	}

	public function getChatId(): int
	{
		return $this->chatId;
	}

	/**
	 * @return int[]
	 */
	public function getUserIds(): array
	{
		return $this->userIds;
	}

	/**
	 * @return int[]
	 */
	public function getMutedUserIds(): array
	{
		return $this->mutedUserIds;
	}

	public function isAddToRecent(): bool
	{
		return $this->addToRecent;
	}

	public function getUnreadMarkedMessageId(): ?int
	{
		return $this->unreadMarkedMessageId;
	}

	public function isWithMessage(): bool
	{
		return $this->withMessage;
	}

	public function isHideHistory(): bool
	{
		return $this->hideHistory;
	}

	protected function validateInternal(): ValidationResult
	{
		$result = new ValidationResult();

		if ($this->chatId <= 0)
		{
			$result->addError(new ChatError(ChatError::WRONG_TARGET_CHAT));
		}

		if ($this->userIds === [])
		{
			$result->addError(new ChatError(ChatError::USER_ID_EMPTY_ERROR));
		}

		if ($this->unreadMarkedMessageId !== null && ($this->unreadMarkedMessageId <= 0 || !$this->addToRecent))
		{
			$result->addError(new ChatError(ChatError::WRONG_PARAMETER));
		}

		return $result;
	}

	protected function executeInternal(): Result
	{
		return ServiceLocator::getInstance()->get(AddMembersHandler::class)($this);
	}
}
