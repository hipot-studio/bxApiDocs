<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a base channel of the end-to-end analytics, as the tile of the channels shows it.
 */
#[Title('CRM tracking channel')]
final class ChannelDto extends Dto
{
	#[Description('Code of the channel: site24, shop24, crm-shop, site, call, mail or order.')]
	public string $code;

	#[Description('Name of the channel as the tile of the channels shows it.')]
	public string $name;

	#[Description('Whether the channel can be set up.')]
	public bool $isConfigurable;

	#[Description('Whether the channel is set up on the portal.')]
	public bool $isConfigured;
}
