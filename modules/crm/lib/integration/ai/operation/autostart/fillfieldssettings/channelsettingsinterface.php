<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;

use JsonSerializable;

interface ChannelSettingsInterface extends JsonSerializable
{
	public function shouldAutostart(int $operationType, array $context = []): bool;
	public function getChannelType(): string;
	public function toArray(): array;

	public static function fromArray(array $data): ?self;
}
