<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class TranscribeRecordScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::TRANSCRIBE_RECORD_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			TranscribeCallRecording::class,
		];
	}

	public function isEnabled(): bool
	{
		return true;
	}
}
