<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Main\Error;

/**
 * What applying one item mutation produced: either a domain rejection
 * ($error, nothing else set) or the authoritative post-mutation state - the
 * re-read item and queue, the ItemDto built off them, and whether a durable
 * state change must be projected and pulled to the other tabs.
 */
final class ItemMutationOutcome
{
	private function __construct(
		public readonly ?Error $error,
		public readonly ?array $itemDto,
		public readonly ?DockedUploadItem $item,
		public readonly ?DockedUploadQueue $queue,
		public readonly bool $stateChanged,
	)
	{
	}

	public static function rejected(Error $error): self
	{
		return new self($error, null, null, null, false);
	}

	public static function applied(
		array $itemDto,
		DockedUploadItem $item,
		DockedUploadQueue $queue,
		bool $stateChanged,
	): self
	{
		return new self(null, $itemDto, $item, $queue, $stateChanged);
	}
}
