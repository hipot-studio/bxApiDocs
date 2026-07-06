<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Message;
use Bitrix\Main\DI\ServiceLocator;

class BuilderUpdater
{
	public function appendBlock(Message $message, array $blockData): BuilderResult
	{
		$builder = $message->getBlocksBuilder();
		if ($builder === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BUILDER_NOT_FOUND)));
		}

		$builderData = $builder->toArray();
		$builderData['blocks'][] = $blockData;
		$builderResult = ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData);
		if (!$builderResult->isSuccess())
		{
			return $builderResult;
		}

		$lastBlock = $builderResult->getBlocksBuilder()->getLastBlock();

		$result = $message
			->setBlocksBuilder($builderResult->getBlocksBuilder())
			->setMessage($builderResult->getBlocksBuilder()->getPayloadText())
			->save()
		;

		if (!$result->isSuccess())
		{
			return $builderResult->addErrors($result->getErrors());
		}

		(new Message\BlocksBuilder\Pull\BuilderBlockAppend($message, $lastBlock))->send();

		return $builderResult->setResult($lastBlock);
	}

	public function deleteBlock(Message $message, string $blockId): BuilderResult
	{
		$builder = $message->getBlocksBuilder();
		if ($builder === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BUILDER_NOT_FOUND)));
		}
		if ($builder->getBlockById($blockId) === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BLOCK_NOT_FOUND)));
		}

		$builderData = $builder->toArray();
		foreach ($builderData['blocks'] as $key => $block)
		{
			if ($block['id'] === $blockId)
			{
				unset($builderData['blocks'][$key]);

				break;
			}
		}

		$builderResult = ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData);
		if (!$builderResult->isSuccess())
		{
			return $builderResult;
		}

		$result = $message
			->setBlocksBuilder($builderResult->getBlocksBuilder())
			->setMessage($builderResult->getBlocksBuilder()->getPayloadText())
			->save()
		;

		if (!$result->isSuccess())
		{
			return $builderResult->addErrors($result->getErrors());
		}

		(new Message\BlocksBuilder\Pull\BuilderBlockDelete($message, $blockId))->send();

		return $builderResult;
	}

	public function updateBlock(Message $message, string $blockId, array $blockData): BuilderResult
	{
		$builder = $message->getBlocksBuilder();
		if ($builder === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BUILDER_NOT_FOUND)));
		}
		if ($builder->getBlockById($blockId) === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BLOCK_NOT_FOUND)));
		}

		$builderData = $builder->toArray();
		foreach ($builderData['blocks'] as $key => $block)
		{
			if ($block['id'] === $blockId)
			{
				$blockData['id'] = $blockId;
				$builderData['blocks'][$key] = $blockData;
			}
		}

		$builderResult = ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData);
		if (!$builderResult->isSuccess())
		{
			return $builderResult;
		}

		$block = $builderResult->getBlocksBuilder()->getBlockById($blockId);

		$result = $message
			->setBlocksBuilder($builderResult->getBlocksBuilder())
			->setMessage($builderResult->getBlocksBuilder()->getPayloadText())
			->save()
		;

		if (!$result->isSuccess())
		{
			return $builderResult->addErrors($result->getErrors());
		}

		(new Message\BlocksBuilder\Pull\BuilderBlockUpdate($message, $block, $blockId))->send();

		return $builderResult->setResult($block);
	}
}
