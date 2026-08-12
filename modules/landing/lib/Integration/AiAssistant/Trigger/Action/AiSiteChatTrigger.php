<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Trigger\Action;

use Bitrix\AiAssistant\Trigger\Action\BaseAction;
use Bitrix\AiAssistant\Trigger\Service\Dto\TriggerInitDto;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;

final class AiSiteChatTrigger extends BaseAction
{
	public static function getCode(): string
	{
		return AiSiteChatBindingContract::TRIGGER_CODE;
	}

	public function isEntityBound(): bool
	{
		return true;
	}

	protected function shouldRunAction(TriggerInitDto $triggerInitDto): bool
	{
		return false;
	}

	protected function runAction(int $userId, int $progressId): bool
	{
		return true;
	}

	protected function needCompleteAfterRun(): bool
	{
		return false;
	}

	public function getMessage(?string $entityId = null): string
	{
		return '';
	}
}
