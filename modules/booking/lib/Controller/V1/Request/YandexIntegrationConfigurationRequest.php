<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\Request;

use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Url;

class YandexIntegrationConfigurationRequest
{
	public function __construct(
		#[ElementsType(className: Resource::class)]
		#[NotEmpty]
		public readonly ResourceCollection $resources,
		#[NotEmpty]
		public readonly string|null $cabinetLink = null,
		#[NotEmpty]
		public readonly string|null $cabinetId = null,
		#[NotEmpty]
		public readonly string|null $timezone = null,
	)
	{
	}

	public static function mapFromArray(array $params): self
	{
		return new self(
			resources: ResourceCollection::mapFromArray($params['resources']),
			cabinetLink: $params['cabinetLink'],
			cabinetId: $params['cabinetId'],
			timezone: $params['timezone'],
		);
	}
}
