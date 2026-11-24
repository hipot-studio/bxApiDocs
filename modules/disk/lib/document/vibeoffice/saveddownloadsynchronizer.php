<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/** @internal */
final class SavedDownloadSynchronizer
{
	/** Success confirms fresh content, or the absence of a relevant edit. */
	public function synchronize(File $file): Result
	{
		try
		{
			foreach ((new SavedEditSessionSelector())->iterate((int)$file->getRealObjectId()) as $session)
			{
				// Ordinary downloads must not require the optional VibeOffice client configuration.
				$result = (new SavedContentSynchronizer())->synchronize($file);
				if (!$result->isSuccess())
				{
					return $result;
				}
				if (($result->getData()['savingInProgress'] ?? null) !== false)
				{
					return (new Result())->addError(new Error(
						'Could not download document.', SavedContentSynchronizer::ERROR_TIMEOUT,
					));
				}

				ServiceLocator::getInstance()->get(DownloadAccess::class)->invalidateObjectOption(
					(int)$file->getRealObjectId(),
				);

				return (new Result())->setData(['synchronized' => true]);
			}

			return (new Result())->setData(['synchronized' => false]);
		}
		catch (\Throwable)
		{
			return (new Result())->addError(new Error(
				'Could not download document.', SavedContentSynchronizer::ERROR_UNKNOWN_OUTCOME,
			));
		}
	}
}
