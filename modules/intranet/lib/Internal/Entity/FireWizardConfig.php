<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity;

class FireWizardConfig
{
	public function __construct(
		private readonly bool $needMoveWebhook,
		private readonly bool $disableIntegrations = false,
	)
	{
	}

	public function needMoveWebhook(): bool
	{
		return $this->needMoveWebhook;
	}

	public function disableIntegrations(): bool
	{
		return $this->disableIntegrations;
	}
}
