<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use JsonSerializable;

final class AuditCard implements JsonSerializable
{
	/**
	 * @param array<string, bool|float|int|string|null> $details
	 * @param array{available: bool, url: ?string, reason: ?string} $objectLink
	 * @param array{code: string, name: ?string, userId: ?int, avatar: ?string, scope: ?string}|null $accessSubject
	 * @param array{name: ?string, color: ?string, avatar: ?string, special: ?string}|null $contextEntity
	 * @param array<string, string> $storageNames
	 */
	public function __construct(
		public readonly AuditListItem $item,
		public readonly ?string $ip,
		public readonly array $details,
		public readonly array $objectLink,
		public readonly ?string $pathSnapshot,
		public readonly ?string $storageName,
		public readonly ?string $parentId,
		public readonly ?string $initiatorAvatar = null,
		public readonly ?array $accessSubject = null,
		public readonly ?array $contextEntity = null,
		public readonly array $storageNames = [],
	)
	{
	}

	public function jsonSerialize(): array
	{
		$data = $this->item->jsonSerialize();
		$data['ip'] = $this->ip;
		$data['details'] = $this->details;
		$data['objectLink'] = $this->objectLink;
		$data['pathSnapshot'] = $this->pathSnapshot;
		$data['storageName'] = $this->storageName;
		$data['parentId'] = $this->parentId;
		$data['canOpenObject'] = $this->objectLink['available'];
		$data['initiator']['avatar'] = $this->initiatorAvatar;
		$data['accessSubject'] = $this->accessSubject;
		$data['contextEntity'] = $this->contextEntity;
		// An empty map would encode as a list; the card reads no names as none.
		$data['storageNames'] = $this->storageNames === [] ? null : $this->storageNames;

		return $data;
	}
}
