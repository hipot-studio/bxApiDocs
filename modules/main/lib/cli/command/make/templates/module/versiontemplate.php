<?php

namespace Bitrix\Main\Cli\Command\Make\Templates\Module;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class VersionTemplate implements Template
{
	public function __construct(
		private readonly string $version,
	)
	{}

	public function getContent(): string
	{
		$now = date('Y-m-d H:i:s');

		return <<<PHP
<?php

\$arModuleVersion = [
	'VERSION' => '{$this->version}',
	'VERSION_DATE' => '{$now}',
];

PHP;
	}
}
