<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Member;

use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Public\Command\AbstractCommand;
use Bitrix\Im\V2\Result;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\ValidationResult;

final class DeleteMembersCommand extends AbstractCommand
{
	public const MAX_USER_COUNT = 50;

	private readonly array $userIds;

	/**
	 * @param int[] $userIds
	 */
	public function __construct(
		private readonly int $chatId,
		array $userIds,
	)
	{
		$this->userIds = Normalizer::toUniquePositiveIntegers($userIds);
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

		if (count($this->userIds) > self::MAX_USER_COUNT)
		{
			$result->addError(new ChatError(ChatError::WRONG_PARAMETER));
		}

		return $result;
	}

	protected function executeInternal(): Result
	{
		return ServiceLocator::getInstance()->get(DeleteMembersHandler::class)($this);
	}
}
