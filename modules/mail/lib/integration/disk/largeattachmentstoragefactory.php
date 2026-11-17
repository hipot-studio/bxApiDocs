<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink\CreateMailAttachmentLinkCommand;
use Bitrix\Main\Loader;

final class LargeAttachmentStorageFactory
{
	public static function getInstance(): LargeAttachmentStorageInterface
	{
		return self::createStorage(self::isRequiredDiskApiAvailable());
	}

	private static function createStorage(bool $isRequiredDiskApiAvailable): LargeAttachmentStorageInterface
	{
		if ($isRequiredDiskApiAvailable)
		{
			return new RealLargeAttachmentStorage(linkGateway: self::createLinkGateway());
		}

		return new StubLargeAttachmentStorage();
	}

	/**
	 * The models of Disk the real storage works with, whatever way the link of a set is created by. The
	 * command of the service link is not among them: without it the storage still works, only by the
	 * previous way of creating the link.
	 */
	private static function isRequiredDiskApiAvailable(): bool
	{
		if (!Loader::includeModule('disk'))
		{
			return false;
		}

		return method_exists(\Bitrix\Disk\Storage::class, 'getFolderForMailAttachments')
			&& method_exists(\Bitrix\Disk\ExternalLink::class, 'canEditSettings');
	}

	/**
	 * The way the link is created is chosen on every call, so a portal that receives the update of Disk
	 * switches over to the commands by itself, without a migration and without a switch of its own.
	 */
	private static function createLinkGateway(): MailAttachmentLinkGateway
	{
		if (class_exists(CreateMailAttachmentLinkCommand::class))
		{
			return new DiskMailAttachmentLinkGateway();
		}

		return new LegacyMailAttachmentLinkGateway();
	}
}

