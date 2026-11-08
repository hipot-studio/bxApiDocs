<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * One address an activity talks through, as {@see ActivityDto::$communications} answers it.
 *
 * A communication always names its channel; the address and the CRM object behind it are null both where
 * nobody attributed the address and where the object is out of reach for the current user. The element
 * itself stays either way. Masking is done by the read scenario, which hands this contract what is already
 * safe to answer.
 *
 * `type` stays a string rather than a closed list: an activity accepts any channel code its provider names,
 * including the ones providers of other modules add.
 */
final class ActivityCommunicationDto extends Dto
{
	public string $type;

	/** The address inside the channel; null where the CRM object behind it is out of reach. */
	public ?string $value;

	public ?int $entityTypeId;

	public ?int $entityId;
}
