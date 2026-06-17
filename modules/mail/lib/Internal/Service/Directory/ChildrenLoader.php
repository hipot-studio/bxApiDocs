<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory;

use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\MailboxDirectory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final readonly class ChildrenLoader
{
	public function __construct(
		private DirectorySerializer $serializer = new DirectorySerializer(),
	)
	{
	}

	/**
	 * @return Result Result data on success: ['items' => DirectoryItem[]]
	 */
	public function load(int $mailboxId, string $dirMd5): Result
	{
		$result = new Result();

		if (trim($dirMd5) === '')
		{
			$result->addError(new Error('Error processing form', 'MAIL_CLIENT_FORM_ERROR'));

			return $result;
		}

		$parent = MailboxDirectory::fetchOneByMailboxIdAndHash($mailboxId, $dirMd5);
		if ($parent === null)
		{
			$result->addError(new Error('Mailbox was not found', 'MAIL_CLIENT_MAILBOX_NOT_FOUND'));

			return $result;
		}

		$helper = new MailboxDirectoryHelper($mailboxId);
		if ($parent->getLevel() >= MailboxDirectoryHelper::getMaxLevelDirs())
		{
			$result->addError(new Error('Maximum nesting levels exceeded', 'MAIL_CLIENT_CONFIG_DIRS_MAX_LEVEL_DIRS'));

			return $result;
		}

		if (!$helper->syncChildren($parent))
		{
			foreach ($helper->getErrors()->toArray() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		$helper->setDirs($helper->getAllLevelByParentId($parent));

		return $result->setData([
			'items' => $this->serializer->serializeTree($helper->buildTreeDirs()),
		]);
	}
}
