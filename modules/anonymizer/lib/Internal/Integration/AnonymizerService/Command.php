<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService;

use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\CommandInterface;
use Bitrix\Anonymizer\Internal\Services\Portal\Region;
use Bitrix\Anonymizer\Public\Context\QuestContext;
use Bitrix\Main\Error;

/**
 * Base command. New commands must be registered in {@see \Bitrix\Anonymizer\Internal\Repository\CommandRegistry}.
 */
abstract class Command implements CommandInterface
{
	protected function getLang(): string
	{
		return (new Region())->getRegion();
	}

	public function processError(QuestContext $context, string $error): void
	{
		$context->error = new Error($error);
	}
}
