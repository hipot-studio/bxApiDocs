<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;

use Bitrix\Crm\Integration\AI\AIManager;

final class ChatChannelSettings extends BaseChannelSettings
{
	public const CHANNEL_TYPE = 'chat';

	private bool $autostartOnlyFirstChat;

	public function __construct(array $autostartOperationTypes, bool $autostartOnlyFirstChat)
	{
		parent::__construct($autostartOperationTypes);

		$this->autostartOnlyFirstChat = $autostartOnlyFirstChat;
	}

	public function getChannelType(): string
	{
		return self::CHANNEL_TYPE;
	}

	public function shouldAutostart(int $operationType, array $context = []): bool
	{
		$checkAutomaticProcessingParams = $context['checkAutomaticProcessingParams'] ?? true;
		if (
			$checkAutomaticProcessingParams
			&& !(AIManager::isAiCallAutomaticProcessingAllowed() && AIManager::isBaasServiceHasPackage())
		)
		{
			return false;
		}

		return in_array($operationType, $this->operationTypes, true);
	}

	public function isAutostartOnlyFirstChat(): bool
	{
		return $this->autostartOnlyFirstChat;
	}

	public function toArray(): array
	{
		return [
			'channelType' => $this->getChannelType(),
			'autostartOperationTypes' => $this->operationTypes,
			'autostartOnlyFirstChat' => $this->autostartOnlyFirstChat,
		];
	}

	public static function fromArray(array $data): ?self
	{
		$types = $data['autostartOperationTypes'] ?? null;
		if (is_array($types))
		{
			$types = (new self([], false))->validateOperationTypes($types);
		}

		$autostartOnlyFirstChat = $data['autostartOnlyFirstChat'] ?? false;

		if (is_array($types) && is_bool($autostartOnlyFirstChat))
		{
			return new self($types, $autostartOnlyFirstChat);
		}

		return null;
	}

	public static function getDefault(): self
	{
		return new self(
			[],
			false
		);
	}
}
