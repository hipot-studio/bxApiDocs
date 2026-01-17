<?php

namespace Bitrix\Main\Cli\Command\Make\Service\MessageHandler;

use Bitrix\Main\Cli\Helper\Namespaces\NamespaceVariationsDto;
use Bitrix\Main\Cli\Helper\Namespaces\NamespaceVariationsDtoTrait;

final class GenerateDto implements NamespaceVariationsDto
{
	use NamespaceVariationsDtoTrait;

	public function __construct(
		public readonly string $name,
		public readonly string $handlerModuleId,
		public readonly string $messageModuleId,
	)
	{}
}
