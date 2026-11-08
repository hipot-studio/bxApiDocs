<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventManager;

final class VersionCleanupGuard
{
	private array $clones = [];

	public function __construct(private readonly int $objectId, private readonly int $savedFileId = 0)
	{
	}

	public function run(callable $mutation): mixed
	{
		$events = EventManager::getInstance();
		$handler = $events->addEventHandler(
			VersionTable::class,
			'OnBeforeDelete',
			fn(Event $event) => $this->protectVersion($event),
		);
		try
		{
			return $mutation();
		}
		finally
		{
			$events->removeEventHandler(VersionTable::class, 'OnBeforeDelete', $handler);
		}
	}

	public function getCloneIds(): array
	{
		return array_values($this->clones);
	}

	private function protectVersion(Event $event): void
	{
		$primary = $event->getParameter('primary');
		$id = is_array($primary) ? ($primary['ID'] ?? 0) : $primary;
		$version = VersionTable::query()
			->setSelect(['OBJECT_ID', 'FILE_ID', 'VIEW_ID'])
			->where('ID', (int)$id)
			->fetch()
		;
		if (!$version || (int)$version['OBJECT_ID'] !== $this->objectId)
		{
			return;
		}
		if ((int)$version['FILE_ID'] <= 0)
		{
			throw new \RuntimeException('Cannot protect a version without a file.');
		}
		if ((int)$version['FILE_ID'] === $this->savedFileId)
		{
			throw new \RuntimeException('Version retention selected the new saved contents.');
		}

		foreach ([(int)$version['FILE_ID'], (int)$version['VIEW_ID']] as $fileId)
		{
			if ($fileId <= 0 || isset($this->clones[$fileId]))
			{
				continue;
			}
			$cloneId = (int)\CFile::CloneFile($fileId);
			if ($cloneId <= 0)
			{
				throw new \RuntimeException('Could not protect the retained version contents.');
			}
			$this->clones[$fileId] = $cloneId;
		}
	}
}
