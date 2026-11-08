<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlViewer;

final class PublicDomainAccessContext
{
	public const VERSION = 1;
	public const SOURCE_TYPE_FILE = 'file';
	public const SOURCE_TYPE_VERSION = 'version';
	public const SOURCE_TYPE_ATTACHED_OBJECT = 'attachedObject';

	private const SOURCE_TYPES = [
		self::SOURCE_TYPE_FILE,
		self::SOURCE_TYPE_VERSION,
		self::SOURCE_TYPE_ATTACHED_OBJECT,
	];

	public function __construct(
		private readonly int $userId,
		private readonly int $objectId,
		private readonly string $sourceType,
		private readonly int $sourceId,
	)
	{
		if ($userId <= 0)
		{
			throw new \InvalidArgumentException('User id must be positive.');
		}

		if ($objectId <= 0)
		{
			throw new \InvalidArgumentException('Object id must be positive.');
		}

		if (!in_array($sourceType, self::SOURCE_TYPES, true))
		{
			throw new \InvalidArgumentException('Source type is invalid.');
		}

		if ($sourceId <= 0)
		{
			throw new \InvalidArgumentException('Source id must be positive.');
		}
	}

	public function getVersion(): int
	{
		return self::VERSION;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getObjectId(): int
	{
		return $this->objectId;
	}

	public function getSourceType(): string
	{
		return $this->sourceType;
	}

	public function getSourceId(): int
	{
		return $this->sourceId;
	}
}
