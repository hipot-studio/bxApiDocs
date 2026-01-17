<?php

namespace Bitrix\Main\Cli\Command\Make\Templates\Module;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class DefaultOptionTemplate implements Template
{
	public function __construct(
		private readonly string $moduleIdNormalized,
	)
	{}

	public function getContent(): string
	{
		return <<<PHP
<?php

\${$this->moduleIdNormalized}_default_option = [
	// 'option name' => 'value',
];

PHP;
	}
}
