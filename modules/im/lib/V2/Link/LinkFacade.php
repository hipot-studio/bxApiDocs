<?php

namespace Bitrix\Im\V2\Link;

use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Link\File\FileService;
use Bitrix\Im\V2\Link\File\PublishedFileHeadIndexer;
use Bitrix\Im\V2\Link\Url\UrlService;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;
use Throwable;

class LinkFacade
{
	use ContextCustomer;
	private Message\Send\SendingConfig $config;

	public function __construct(?Message\Send\SendingConfig $config)
	{
		$this->config = $config ?? new Message\Send\SendingConfig();
	}

	public function saveLinksFromMessage(Message $message): Result
	{
		if (!$this->config->skipUrlIndex())
		{
			$resultUrls = (new UrlService())
				->setContext($this->context)
				->setBackgroundMode(false)
				->saveUrlsFromMessage($message)
			;
		}
		$resultFiles = $this->createFileService()->setContext($this->context)->save($message);
		if ($resultFiles->isSuccess())
		{
			$savedDiskFileIds = $resultFiles->getData()[FileService::SAVED_DISK_FILE_IDS] ?? [];
			if ($savedDiskFileIds !== [])
			{
				Application::getInstance()->addBackgroundJob(function () use ($savedDiskFileIds): void {
					try
					{
						$this->createPublishedFileHeadIndexer()->indexPublishedFiles($savedDiskFileIds);
					}
					catch (Throwable)
					{
					}
				});
			}
		}

		return Result::merge($resultUrls ?? new Result(), $resultFiles);
	}

	protected function createFileService(): FileService
	{
		return new FileService();
	}

	protected function createPublishedFileHeadIndexer(): PublishedFileHeadIndexer
	{
		return new PublishedFileHeadIndexer();
	}
}
