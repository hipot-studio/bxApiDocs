<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access;

use Bitrix\Rest\Internal\Access\Webhook\WebhookAction;
use Bitrix\Rest\Internal\Access\Webhook\WebhookAccessController;
use Bitrix\Rest\Internal\Access\Webhook\Model\IncomingWebhookModel;
use Bitrix\Rest\Internal\Entity;

class WebhookAccessChecker
{
	private WebhookAccessController $controller;

	public function __construct(private readonly int $userId)
	{
		$this->controller = WebhookAccessController::getInstance($this->userId);
	}

	public function canCreateIncomingWebhook(): bool
	{
		return $this->controller->check(WebhookAction::CreateIncomingWebhook);
	}

	public function canCreateOwnIncomingWebhook(): bool
	{
		return $this->controller->check(WebhookAction::CreateOwnIncomingWebhook);
	}

	public function canManageIncomingWebhook(Entity\IncomingWebhook\IncomingWebhook $incomingWebhook): bool
	{
		return $this->controller->check(
			WebhookAction::ManageIncomingWebhook,
			IncomingWebhookModel::createFromWebhook($incomingWebhook),
		);
	}

	public function canManageAnyIncomingWebhook(): bool
	{
		return $this->controller->check(WebhookAction::ManageIncomingWebhook);
	}

	public function canManageOwnIncomingWebhook(): bool
	{
		return $this->controller->check(WebhookAction::ManageOwnIncomingWebhook);
	}
}
