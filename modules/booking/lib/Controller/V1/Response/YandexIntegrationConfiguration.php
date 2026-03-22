<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\Response;

use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Service\Integration\IntegrationStatusEnum;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

class YandexIntegrationConfiguration implements JsonSerializable, Arrayable
{
	public function __construct(
		public readonly IntegrationStatusEnum $status,
		public readonly array $catalogPermissions,
		public readonly bool $isResourceSkuRelationsSaved,
		public readonly ResourceCollection $resources,
		public readonly array $settings,
		public readonly string|null $cabinetLink = null,
		public readonly string|null $timezone = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'status' => $this->status->value,
			'catalogPermissions' => $this->catalogPermissions,
			'isResourceSkuRelationsSaved' => $this->isResourceSkuRelationsSaved,
			'cabinetLink' => $this->cabinetLink,
			'timezone' => $this->timezone,
			'resources' => $this->resources->toArray(),
			'settings' => $this->settings,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
