<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookFilter;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookPager;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookSort;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Public\Provider\Dto\IncomingWebhookDto;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookFilter;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookParams;

class IncomingWebhookProvider
{
	public function __construct(
		private readonly IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
	)
	{
	}

	/**
	 * @return \Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook[]
	 */
	public function getListByUser(int $userId, int $offset = 0, int $limit = 50): array
	{
		return $this->repository->getListByUser($userId, $offset, $limit);
	}

	/**
	 * @return IncomingWebhookDto[]
	 */
	public function getList(IncomingWebhookParams $params): array
	{
		$sort = new WebhookSort($params->getSort() ?? []);
		$pager = new WebhookPager($params->getOffset(), $params->getLimit());

		return $this->repository->getList(
			filter: $this->toWebhookFilter($params->webhookFilter),
			sort: $sort,
			pager: $pager,
		)->map($this->toDto(...));
	}

	public function getCount(IncomingWebhookFilter $filter): int
	{
		return $this->repository->getCount($this->toWebhookFilter($filter));
	}

	private function toWebhookFilter(IncomingWebhookFilter $filter): WebhookFilter
	{
		return (new WebhookFilter())
			->userId($filter->userId)
			->scopes($filter->scopes !== [] ? $filter->scopes : null)
			->externalAttributes($filter->attributes !== [] ? $filter->attributes : null)
			->type($filter->type)
		;
	}

	private function toDto(IncomingWebhook $webhook): IncomingWebhookDto
	{
		return new IncomingWebhookDto(
			id: (int)$webhook->getId(),
			userId: $webhook->getUserId(),
			webhookUrl: \CRestUtil::getWebhookEndpoint($webhook->getPassword(), $webhook->getUserId()),
			active: $webhook->isActive(),
			title: $webhook->getTitle(),
			scopes: $webhook->getScopes(),
			dateCreate: $webhook->getDateCreate(),
			dateLogin: $webhook->getDateLogin(),
			lastIp: $webhook->getLastIp(),
		);
	}
}
