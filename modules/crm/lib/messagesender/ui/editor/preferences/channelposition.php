<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Preferences;

use Bitrix\Crm\Dto\Dto;

final class ChannelPosition extends Dto
{
	public string $channelId;
	public bool $isHidden;

	protected function getValidators(array $fields): array
	{
		return [
			new \Bitrix\Crm\Dto\Validator\RequiredField($this, 'channelId'),
			new \Bitrix\Crm\Dto\Validator\NotEmptyField($this, 'channelId'),
			new \Bitrix\Crm\Dto\Validator\RequiredField($this, 'isHidden'),
		];
	}
}