<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Webhook\Model;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;

final class IncomingWebhookModel implements AccessibleItem
{
	private ?IncomingWebhook $webhook = null;

	public static function createFromId(?int $itemId): self
	{
		$model = new self();

		if ($itemId !== null && $itemId > 0)
		{
			$model->webhook = (new IncomingWebhookRepository())->getById($itemId);
		}

		return $model;
	}

	public static function createFromWebhook(IncomingWebhook $webhook): self
	{
		$model = new self();
		$model->webhook = $webhook;

		return $model;
	}

	public function getId(): int
	{
		return $this->webhook?->getId() ?? 0;
	}

	public function getWebhook(): ?IncomingWebhook
	{
		return $this->webhook;
	}

	public function getUserId(): ?int
	{
		return $this->webhook?->getUserId();
	}
}
