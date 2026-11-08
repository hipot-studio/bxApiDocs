<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity\ValueObject;

use Bitrix\Main\ArgumentException;

/**
 * Metadata of one file attached to an activity: what the storage can say about an element without
 * opening it. Unlike {@see \Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement}, which a write
 * stores as a bare identifier, this is the answer of the storage about such an element.
 */
final class ActivityFile
{
	private readonly int $id;

	private readonly string $name;

	private readonly ?string $url;

	private readonly ?string $downloadUrl;

	/**
	 * @param int $id Element inside the storage of the activity.
	 * @param string $name Name the storage shows the file under.
	 * @param string|null $url Address the file is shown at, null where the storage names none.
	 * @param string|null $downloadUrl Address the file is handed over at, null where the storage names none.
	 * @throws ArgumentException on a non-positive id.
	 */
	public function __construct(int $id, string $name, ?string $url, ?string $downloadUrl)
	{
		if ($id <= 0)
		{
			throw new ArgumentException("Activity file ID must be positive, got: {$id}");
		}

		$this->id = $id;
		$this->name = $name;
		$this->url = $url;
		$this->downloadUrl = $downloadUrl;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getUrl(): ?string
	{
		return $this->url;
	}

	public function getDownloadUrl(): ?string
	{
		return $this->downloadUrl;
	}
}
