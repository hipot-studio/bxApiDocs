<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Contract\Repository;

use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookCollection;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookFilter;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookPager;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookSort;

interface IncomingWebhookRepositoryInterface extends RepositoryInterface
{
	public function getByWebhookId(string $webhookId): ?IncomingWebhook;

	/**
	 * @return IncomingWebhook[]
	 */
	public function getListByUser(int $userId, int $offset = 0, int $limit = 50): array;

	/**
	 * Webhooks matching the given criteria. Defaults to ID descending when no sort is given.
	 */
	public function getList(
		WebhookFilter $filter,
		?WebhookSort $sort = null,
		?WebhookPager $pager = null,
	): IncomingWebhookCollection;

	public function getCount(WebhookFilter $filter): int;
}
