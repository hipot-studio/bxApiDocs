<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Embed;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class EmbedCreateSettings
{
	private const OPTION_EMBED_CREATE_IN_IFRAME = 'embed_create_in_iframe';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function isEnabled(): bool
	{
		return $this->options->get(self::OPTION_EMBED_CREATE_IN_IFRAME, 'N') === 'Y';
	}

	public function setEnabled(bool $value): void
	{
		$this->options->set(self::OPTION_EMBED_CREATE_IN_IFRAME, $value ? 'Y' : 'N');
	}
}
