<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Settings;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

#[Description('CRM setting available to the current user')]
final class SettingDto extends Dto
{
	#[Description('Stable string identifier of this CRM setting.')]
	public string $id;

	#[Description('Current JSON value of this CRM setting.')]
	public mixed $value;

	#[Description('Display text and value constraints for this setting.')]
	public SettingMetaDto $meta;
}
