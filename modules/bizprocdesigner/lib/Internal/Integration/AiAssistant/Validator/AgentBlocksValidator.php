<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AgentBlocksValidator
{
	private readonly AgentBlockValidator $blockValidator;

	private AgentBlockCollection $validBlocks;
	/**
	 * @var list<string>
	 */
	private array $blockIds = [];

	public function __construct(
		?AgentBlockValidator $blockValidator = null,
	)
	{
		$this->validBlocks = new AgentBlockCollection();
		$this->blockValidator = $blockValidator ?? new AgentBlockValidator(
			blockDescriptionCache: new BlockDescriptionCache(),
		);
	}

	public function validate(mixed $blocks, DocumentDescription $documentType, string $path = ''): Result
	{
		$this->blockIds = [];
		$this->validBlocks = new AgentBlockCollection();

		if (!is_array($blocks))
		{
			return (new Result())->addError(new Error('blocks should be array'));
		}

		if (empty($blocks))
		{
			return (new Result())->addError(new Error('blocks should be not empty array'));
		}

		$result = new Result();
		foreach ($blocks as $key => $block)
		{
			$blockValidateResult = $this->blockValidator->validate(
				block: $block,
				documentType: $documentType,
				blackListIds: $this->blockIds,
				path: "{$path}.{$key}",
			);
			$result->addErrors($blockValidateResult->getErrors());
			if ($blockValidateResult->isSuccess())
			{
				$this->validBlocks->add($this->blockValidator->getValidBlock());
			}
			if ($this->blockValidator->getId() !== null)
			{
				$this->blockIds[] = $this->blockValidator->getId();
			}
		}

		return $result;
	}

	public function getValidBlocks(): AgentBlockCollection
	{
		return $this->validBlocks;
	}

	/**
	 * @return list<string>
	 */
	public function getBlockIds(): array
	{
		return $this->blockIds;
	}
}