<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\IncomingWebhook;

use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Public\Provider\Dto as Provider;
use Bitrix\Rest\V3\Dto\DtoCollection;

class IncomingWebhookDtoMapper
{
	public function toDto(IncomingWebhook $webhook): IncomingWebhookDto
	{
		$dto = new IncomingWebhookDto();
		$dto->title = $webhook->getTitle();
		$dto->scopes = $webhook->getScopes();
		$dto->active = $webhook->isActive();
		$dto->dateCreate = $webhook->getDateCreate()?->toString();
		$dto->userId = $webhook->getUserId();
		$dto->attributes = $this->mapExternalAttributes($webhook)->toArray();

		$dto->url = \CRestUtil::getWebhookEndpoint($webhook->getPassword(), $webhook->getUserId());

		return $dto;
	}

	/**
	 * @param Provider\IncomingWebhookDto[] $webhookList
	 */
	public function toDtoCollection(array $webhookList): DtoCollection
	{
		$collection = new DtoCollection(IncomingWebhookDto::class);

		foreach ($webhookList as $webhook)
		{
			$collection->add($this->toListItemDto($webhook));
		}

		return $collection;
	}

	private function toListItemDto(Provider\IncomingWebhookDto $webhook): IncomingWebhookDto
	{
		$dto = new IncomingWebhookDto();
		$dto->title = $webhook->getTitle();
		$dto->scopes = $webhook->getScopes();
		$dto->active = $webhook->isActive();
		$dto->dateCreate = $webhook->getDateCreate()?->toString();
		$dto->userId = $webhook->getUserId();
		$dto->url = $webhook->getWebhookUrl();

		return $dto;
	}

	public function nullDto(): IncomingWebhookDto
	{
		$dto = new IncomingWebhookDto();
		$dto->url = '';
		$dto->scopes = [];
		$dto->active = false;
		$dto->userId = null;
		$dto->attributes = [];

		return $dto;
	}

	private function mapExternalAttributes(IncomingWebhook $webhook): DtoCollection
	{
		$collection = new DtoCollection(IncomingWebhookAttributeDto::class);
		foreach ($webhook->getExternalAttributes() as $attribute)
		{
			$dto = new IncomingWebhookAttributeDto();
			$collection->add($dto);
			$dto->code = $attribute->getCode();
			$dto->value = $attribute->getValue();
		}

		return $collection;
	}
}
