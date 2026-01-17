<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class LangTemplate implements Template
{
	public function __construct(
		private readonly array $phrases = [],
	)
	{}

	public function getContent(): string
	{
		$content = "<?php\n\n";

		foreach ($this->phrases as $name => $text)
		{
			$content .= sprintf(
				'$MESS["%s"] = "%s";' . "\n",
				addslashes($name),
				addslashes($text),
			);
		}

		return $content;
	}
}
