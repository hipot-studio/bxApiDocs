<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\ActiveRecord;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Rest\RestEntity;

interface Folder extends AccessCheckable, RestEntity, ActiveRecord
{
	public function getId(): ?int;

	public function getUserId(): ?int;

	public function getType(): string;

	public function getCode(): ?string;

	public function getTitle(): string;

	public function getSort(): int;

	public function getRecentSection(): ?string;

	public function getParentId(): int;

	public function containsChat(Chat $chat): bool;

	public function displaysNestedInRoot(): bool;

	public function getRecentParentScope(): ?int;
}
