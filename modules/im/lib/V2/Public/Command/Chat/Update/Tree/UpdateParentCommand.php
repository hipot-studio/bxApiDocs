<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Update\Tree;

use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Public\Command\AbstractCommand;
use Bitrix\Im\V2\Result;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\ValidationResult;

final class UpdateParentCommand extends AbstractCommand
{
	private readonly array $targetChatIds;

	/**
	 * @param int[] $targetChatIds
	 */
	public function __construct(
		array $targetChatIds,
		private readonly int $parentChatId,
	)
	{
		$this->targetChatIds = Normalizer::toUniquePositiveIntegers($targetChatIds);
	}

	/**
	 * @return int[]
	 */
	public function getTargetChatIds(): array
	{
		return $this->targetChatIds;
	}

	public function getParentChatId(): int
	{
		return $this->parentChatId;
	}

	protected function validateInternal(): ValidationResult
	{
		$result = new ValidationResult();

		$targetChatIds = $this->getTargetChatIds();
		if ($targetChatIds === [])
		{
			$result->addError(new ChatError(ChatError::WRONG_TARGET_CHAT));
		}

		$parentChatId = $this->getParentChatId();
		if ($parentChatId < 0)
		{
			$result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		if (in_array($parentChatId, $targetChatIds, true))
		{
			$result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		return $result;
	}

	protected function executeInternal(): Result
	{
		return ServiceLocator::getInstance()->get(UpdateParentHandler::class)($this);
	}
}
