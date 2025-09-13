<?php

namespace Bitrix\HumanResources\Command\Structure\Node;

use Bitrix\HumanResources\Command\AbstractCommand;
use Bitrix\HumanResources\Command\Structure\Node\Handler\SaveNodeChatsCommandHandler;
use Bitrix\HumanResources\Result\Command\Structure\SaveNodeChatsResult;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\HumanResources\Item;

/**
 * @extends AbstractCommand<SaveNodeChatsResult>
 */
class SaveNodeChatsCommand extends AbstractCommand
{
	const CHAT_INDEX = 'chat';
	const CHANNEL_INDEX = 'channel';

	public readonly array $ids;
	public readonly array $removeIds;

	/**
	 * @param Item\Node $node
	 * @param array $createDefault
	 * @param array $ids
	 */
	public function __construct(
		public readonly Item\Node $node,
		public readonly array $createDefault = [self::CHAT_INDEX => false, self::CHANNEL_INDEX => false],
		array $ids = [self::CHAT_INDEX => [], self::CHANNEL_INDEX => []],
		array $removeIds = [],
	)
	{
		$this->ids = array_merge([self::CHAT_INDEX => [], self::CHANNEL_INDEX => []], $ids);
		$this->removeIds = $removeIds;
	}

	protected function validate(): bool
	{
		if (!isset($this->createDefault[self::CHAT_INDEX])
			|| !isset($this->createDefault[self::CHANNEL_INDEX])
			|| !is_array($this->ids[self::CHAT_INDEX])
			|| !is_array($this->ids[self::CHANNEL_INDEX])
		)
		{
			return false;
		}

		if (!array_product(array_map('is_numeric', $this->ids[self::CHAT_INDEX]))
			|| !array_product(array_map('is_numeric', $this->ids[self::CHANNEL_INDEX]))
			|| !array_product(array_map('is_numeric', $this->removeIds))
		)
		{
			return false;
		}

		return true;
	}

	protected function execute(): SaveNodeChatsResult
	{
		try
		{
			return (new SaveNodeChatsCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			$result = (new SaveNodeChatsResult());
			$result->addError(
				new Error(
					$e->getMessage(),
					$e->getCode(),
				),
			);

			return $result;
		}
	}
}