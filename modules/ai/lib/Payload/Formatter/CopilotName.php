<?php

declare(strict_types=1);

namespace Bitrix\AI\Payload\Formatter;

use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

class CopilotName extends Formatter implements IFormatter
{
	private const MARKER = '{copilot_name}';
	private const COPILOT_NAME_FALLBACK = 'CoPilot';

	/**
	 * @inheritDoc
	 */
	public function format(array $additionalMarkers = []): string
	{
		if (str_contains($this->text, self::MARKER))
		{
			$this->text = str_replace(self::MARKER, $this->getCopilotName(), $this->text);
		}

		return $this->text;
	}

	protected function getCopilotName(): string
	{
		if (Loader::includeModule('ui'))
		{
			return (new CopilotNameService())->getCopilotName();
		}

		return self::COPILOT_NAME_FALLBACK;
	}
}

