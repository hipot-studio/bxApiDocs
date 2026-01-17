<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Security\Random;

final class SummarizeTranscript implements StubInterface
{
	public function makeStub(): string
	{
		return 'Stub call summary with unique text: ' . Random::getString(20);
	}
}
