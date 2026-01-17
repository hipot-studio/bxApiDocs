<?php

namespace Bitrix\Crm\Activity\Provider\Sms;

use Bitrix\Crm\Dto\Caster;

final class MessageDto extends \Bitrix\Crm\Dto\Dto
{
	public ?string $senderId = null;
	public ?string $from = null;
	public ?string $to = null;
	public ?string $body = null;
	public ?string $template = null;
	public ?array $placeholders = null;
	public ?int $templateOriginalId = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'placeholders' => new Caster\CollectionCaster(new Caster\ObjectCaster(TemplatePlaceholderDto::class)),
			default => null,
		};
	}
}
