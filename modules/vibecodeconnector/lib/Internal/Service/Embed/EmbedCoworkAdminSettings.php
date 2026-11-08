<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Embed;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class EmbedCoworkAdminSettings
{
	private const OPTION_EMBED_COWORK_ADMIN_ENABLED = 'embed_cowork_admin_enabled';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function isEnabled(): bool
	{
		return $this->options->get(self::OPTION_EMBED_COWORK_ADMIN_ENABLED, 'Y') === 'Y';
	}

	public function setEnabled(bool $value): void
	{
		$this->options->set(self::OPTION_EMBED_COWORK_ADMIN_ENABLED, $value ? 'Y' : 'N');
	}
}
