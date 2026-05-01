<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity;

class FireWizardConfig
{
	public function __construct(
		private readonly bool $needMoveWebhook,
	)
	{
	}

	public function needMoveWebhook(): bool
	{
		return $this->needMoveWebhook;
	}
}