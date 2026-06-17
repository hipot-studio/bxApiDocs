<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory;

use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Internal\Entity\Directory\AssignedDirectory;
use Bitrix\Mail\Internal\Entity\Directory\DirectoryItem;
use Bitrix\Mail\Internals\Entity\MailboxDirectory;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

final readonly class DirectorySerializer
{
	/**
	 * @param MailboxDirectory[] $directories
	 * @return DirectoryItem[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function serializeTree(array $directories): array
	{
		return array_values(array_map(
			fn (MailboxDirectory $dir) => $this->serialize($dir),
			$directories,
		));
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function serialize(MailboxDirectory $dir): DirectoryItem
	{
		return new DirectoryItem(
			id: $dir->getId(),
			dirMd5: $dir->getDirMd5(),
			name: $dir->getName(),
			formattedName: $dir->getFormattedName(),
			path: (string)$dir->getPath(),
			level: $dir->getLevel(),
			isSync: $dir->isSync(),
			isDisabled: $dir->isDisabled(),
			isContainer: $dir->isVirtualFolder(),
			hasChild: MailboxDirectoryHelper::hasChildren($dir->getFlags()),
			type: $this->resolveType($dir),
			children: $this->serializeTree($dir->getChildren()),
		);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function serializeAssigned(?MailboxDirectory $dir): ?AssignedDirectory
	{
		if ($dir === null)
		{
			return null;
		}

		return new AssignedDirectory(
			dirMd5: $dir->getDirMd5(),
			formattedName: $dir->getFormattedName(),
			path: (string)$dir->getPath(),
			type: $this->resolveType($dir),
		);
	}

	private function resolveType(MailboxDirectory $dir): string
	{
		return match (true)
		{
			$dir->isIncome() => 'default',
			$dir->isOutcome() => 'outcome',
			$dir->isDraft() => 'drafts',
			$dir->isTrash() => 'trash',
			$dir->isSpam() => 'spam',
			default => 'custom',
		};
	}
}
