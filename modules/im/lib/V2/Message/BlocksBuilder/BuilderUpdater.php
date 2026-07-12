<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Link\File\FileService;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Message\Update\UpdateService;
use Bitrix\Main\DI\ServiceLocator;

class BuilderUpdater
{
	public function appendBlock(Message $message, array $blockData): BuilderResult
	{
		$builder = $message->getBlocksBuilder();
		if ($builder === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BLOCK_NOT_FOUND)));
		}

		$chat = $message->getChat();
		$builderData = $builder->toArray();
		$builderData['elements'][] = $blockData;
		$builderResult = ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData, $chat);
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

		$this->saveFileLinks($lastBlock, $message);

		(new Message\BlocksBuilder\Pull\MessageBlockElementAppend($message, $lastBlock))->send();
		$this->onAfterMessageUpdate($message);

		return $builderResult;
	}

	public function deleteBlock(Message $message, string $blockId): BuilderResult
	{
		$builder = $message->getBlocksBuilder();
		if ($builder === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BLOCK_NOT_FOUND)));
		}

		$deletedBlock = $builder->getBlockById($blockId);
		if ($deletedBlock === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::ELEMENT_NOT_FOUND)));
		}

		$builderData = $builder->toArray();
		foreach ($builderData['elements'] as $key => $block)
		{
			if ($block['id'] === $blockId)
			{
				unset($builderData['elements'][$key]);

				break;
			}
		}

		$chat = $message->getChat();
		$builderResult = ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData, $chat);
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

		$this->deleteFileLinks($deletedBlock, $message);

		(new Message\BlocksBuilder\Pull\MessageBlockElementDelete($message, $blockId))->send();
		$this->onAfterMessageUpdate($message);

		return $builderResult;
	}

	public function updateBlock(Message $message, string $blockId, array $blockData): BuilderResult
	{
		$builder = $message->getBlocksBuilder();
		if ($builder === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::BLOCK_NOT_FOUND)));
		}
		if ($builder->getBlockById($blockId) === null)
		{
			return (new BuilderResult())->addError((new BuilderError(BuilderError::ELEMENT_NOT_FOUND)));
		}

		$oldBlock = $builder->getBlockById($blockId);

		$builderData = $builder->toArray();
		foreach ($builderData['elements'] as $key => $block)
		{
			if ($block['id'] === $blockId)
			{
				$blockData['id'] = $blockId;
				$builderData['elements'][$key] = $blockData;

				break;
			}
		}

		$chat = $message->getChat();
		$builderResult = ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData, $chat);
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

		$this->deleteFileLinks($oldBlock, $message);
		$this->saveFileLinks($block, $message);

		(new Message\BlocksBuilder\Pull\MessageBlockElementUpdate($message, $block, $blockId))->send();
		$this->onAfterMessageUpdate($message);

		return $builderResult;
	}

	protected function saveFileLinks(?AbstractBlock $block, Message $message): void
	{
		if ($block === null)
		{
			return;
		}

		$fileIds = $block->getFiles();
		if (empty($fileIds))
		{
			return;
		}

		(new FileService())->saveFilesFromMessage($fileIds, $message);
	}

	protected function deleteFileLinks(?AbstractBlock $block, Message $message): void
	{
		if ($block === null)
		{
			return;
		}

		$fileIds = $block->getFiles();
		if (empty($fileIds))
		{
			return;
		}

		(new FileService())->deleteFilesByDiskFileIds($fileIds, $message);
	}

	protected function onAfterMessageUpdate(Message $message): void
	{
		(new UpdateService($message))->onAfterMessageUpdate();
	}
}
