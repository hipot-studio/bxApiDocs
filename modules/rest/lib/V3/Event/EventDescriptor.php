<?php

namespace Bitrix\Rest\V3\Event;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Event\Payload\PayloadInterface;

final readonly class EventDescriptor
{
	public function __construct(
		public string $module,
		public string $name,
		public PayloadInterface $payload,
		public array $scopes,
		public array $options = [],
		public ?array $additionalData = null,
		public ?LocalizableMessage $title = null,
		public ?LocalizableMessage $description = null,
	)
	{
	}
}