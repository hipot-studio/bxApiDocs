<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Link\File\FileService;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
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
		$builderService = ServiceLocator::getInstance()->get(BuilderService::class);
		$builderResult = $builderService->create($builderData, $chat, $builder);
		if (!$builderResult->isSuccess())
		{
			return $builderResult;
		}

		$lastBlock = $builderResult->getBlocksBuilder()->getLastBlock();
		$previousPayloadText = $message->getMessage();
		$previousFileIds = $builder->getFiles();

		$result = $message
			->setBlocksBuilder($builderResult->getBlocksBuilder())
			->setMessage($builderResult->getBlocksBuilder()->getPayloadText())
			->save()
		;

		if (!$result->isSuccess())
		{
			return $builderResult->addErrors($result->getErrors());
		}

		$this->synchronizeFileLinks($message, $previousFileIds);

		(new Message\BlocksBuilder\Pull\MessageBlockElementAppend($message, $lastBlock))->send();
		$this->onAfterMessageUpdate($message, $previousPayloadText, $previousFileIds);

		return $builderResult;
	}

	public function deleteBlock(Message $message, string $blockId): BuilderResult
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
		$builderService = ServiceLocator::getInstance()->get(BuilderService::class);
		$builderResult = $builderService->create($builderData, $chat, $builder);
		if (!$builderResult->isSuccess())
		{
			return $builderResult;
		}

		$previousPayloadText = $message->getMessage();
		$previousFileIds = $builder->getFiles();

		$result = $message
			->setBlocksBuilder($builderResult->getBlocksBuilder())
			->setMessage($builderResult->getBlocksBuilder()->getPayloadText())
			->save()
		;

		if (!$result->isSuccess())
		{
			return $builderResult->addErrors($result->getErrors());
		}

		$this->synchronizeFileLinks($message, $previousFileIds);

		(new Message\BlocksBuilder\Pull\MessageBlockElementDelete($message, $blockId))->send();
		$this->onAfterMessageUpdate($message, $previousPayloadText, $previousFileIds);

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
		$builderService = ServiceLocator::getInstance()->get(BuilderService::class);
		$builderResult = $builderService->create($builderData, $chat, $builder);
		if (!$builderResult->isSuccess())
		{
			return $builderResult;
		}

		$block = $builderResult->getBlocksBuilder()->getBlockById($blockId);
		$previousPayloadText = $message->getMessage();
		$previousFileIds = $builder->getFiles();

		$result = $message
			->setBlocksBuilder($builderResult->getBlocksBuilder())
			->setMessage($builderResult->getBlocksBuilder()->getPayloadText())
			->save()
		;

		if (!$result->isSuccess())
		{
			return $builderResult->addErrors($result->getErrors());
		}

		$this->synchronizeFileLinks($message, $previousFileIds);

		(new Message\BlocksBuilder\Pull\MessageBlockElementUpdate($message, $block, $blockId))->send();
		$this->onAfterMessageUpdate($message, $previousPayloadText, $previousFileIds);

		return $builderResult;
	}

	/**
	 * @param int[] $previousBuilderFileIds
	 */
	private function synchronizeFileLinks(Message $message, array $previousBuilderFileIds): void
	{
		$attachmentFileIds = $message->getParams()->isSet(Params::FILE_ID)
			? $message->getParams()->get(Params::FILE_ID)->getValue()
			: [];
		$currentBuilderFileIds = $message->getBlocksBuilder()?->getFiles() ?? [];

		(new FileService())->synchronizeFilesFromMessage(
			array_merge($attachmentFileIds, $previousBuilderFileIds),
			array_merge($attachmentFileIds, $currentBuilderFileIds),
			$message,
		);
	}

	/**
	 * Block updates may leave the message text intact (a progress refresh, a service block): such an
	 * update still notifies clients, but does not need the text-derived part of the post-update. The
	 * files are looked at next to the text, because a changed set of them does not have to show up in
	 * the text - a gallery contributes no text at all, and a replacement file may carry the same name -
	 * while the preview a client rebuilds after a recent touch does change.
	 *
	 * @param int[] $previousFileIds
	 */
	protected function onAfterMessageUpdate(
		Message $message,
		?string $previousPayloadText,
		array $previousFileIds,
	): void
	{
		$updateService = new UpdateService($message);

		$keepsPayloadText = $message->getMessage() === $previousPayloadText;
		$keepsFiles = ($message->getBlocksBuilder()?->getFiles() ?? []) === $previousFileIds;

		if ($keepsPayloadText && $keepsFiles)
		{
			$updateService->withoutTextDerivedUpdate();
		}

		$updateService->onAfterMessageUpdate();
	}
}
