<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserField;

use Bitrix\Main\Entity\EntityInterface;

interface UserField extends EntityInterface
{
	public function getValue(): mixed;

	public function getTitle(): string;

	public function getId(): string;

	public function isEditable(): bool;

	public function isShowAlways(): bool;

	public function isValid(): bool;
}
