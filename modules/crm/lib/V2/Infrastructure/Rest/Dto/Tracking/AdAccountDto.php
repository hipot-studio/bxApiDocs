<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM tracking advertising account')]
final class AdAccountDto extends Dto
{
	public const OUTPUT_FIELDS = [
		'provider',
		'clientId',
		'accountId',
		'name',
		'capabilities',
		'isConnected',
	];

	#[Description('Advertising provider derived from the tracking source.')]
	public string $provider;

	#[Description('Identifier of the authorized advertising client as a string.')]
	public string $clientId;

	#[Description('Advertising account identifier as a string; null for Yandex Direct.')]
	public ?string $accountId;

	#[Description('Name of the advertising account.')]
	public string $name;

	#[Description('Available operations: {"import": boolean, "tree": string[]} with supported tree levels.')]
	public array $capabilities;

	#[Description('Whether this account is connected to the tracking source.')]
	public bool $isConnected;
}
