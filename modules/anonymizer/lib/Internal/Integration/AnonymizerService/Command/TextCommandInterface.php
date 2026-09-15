<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command;

interface TextCommandInterface
{
	public function setText(string $text);
}