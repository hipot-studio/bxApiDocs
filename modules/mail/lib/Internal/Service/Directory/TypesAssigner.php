<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory;

use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\MailboxDirectory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final readonly class TypesAssigner
{
	/**
	 * @param array<int, array{dirMd5?: string, type?: string}> $dirs
	 */
	public function assign(int $mailboxId, array $dirs): Result
	{
		$result = new Result();

		foreach ($dirs as $dir)
		{
			$type = $this->normalizeType(!empty($dir['type']) ? (string)$dir['type'] : null);
			$hash = !empty($dir['dirMd5']) ? (string)$dir['dirMd5'] : null;

			if (!$type || !$hash)
			{
				continue;
			}

			$row = MailboxDirectory::fetchOneByMailboxIdAndHash($mailboxId, $hash);
			if ($row === null)
			{
				continue;
			}

			try
			{
				MailboxDirectory::resetDirsTypes($mailboxId, $type);

				$updateResult = MailboxDirectory::update(
					$row->getId(),
					[
						$type => MailboxDirectoryTable::ACTIVE,
					],
				);

				if (!$updateResult->isSuccess())
				{
					foreach ($updateResult->getErrors() as $error)
					{
						$result->addError($error);
					}
				}
			}
			catch (\Throwable $e)
			{
				$result->addError(new Error($e->getMessage(), 'MAIL_CLIENT_DIRS_TYPE_SAVE_ERROR'));
			}
		}

		return $result;
	}

	private function normalizeType(?string $type): ?string
	{
		if (!$type)
		{
			return null;
		}

		if (MailboxDirectoryHelper::isDirsTypes($type))
		{
			return $type;
		}

		return match ($type)
		{
			'outcome' => MailboxDirectoryTable::TYPE_OUTCOME,
			'trash' => MailboxDirectoryTable::TYPE_TRASH,
			'spam' => MailboxDirectoryTable::TYPE_SPAM,
			default => null,
		};
	}
}
