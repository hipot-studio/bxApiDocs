<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

interface OpeningNoticeCodeAware
{
	public const OBJECT_LOCK_EDIT_VIEW = 'objectLockEditView';
	public const ALLOWED_CODES = [
		self::OBJECT_LOCK_EDIT_VIEW,
	];

	public function setOpeningNoticeCode(?string $code): void;
}
