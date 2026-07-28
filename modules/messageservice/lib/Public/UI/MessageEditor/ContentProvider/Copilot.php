<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;

final class Copilot extends ContentProvider implements Showable
{
	private const LOCKED_SLIDER_CODE = 'limit_copilot_off';
	private const REGION_BLACKLIST = ['ua', 'cn'];

	public function __construct(
		private readonly array $config = [],
	)
	{
	}

	public function getId(): string
	{
		return 'copilot';
	}

	public function isShown(): bool
	{
		return $this->isRegionAvailable()
			&& Loader::includeModule('ai');
	}

	protected function getCustomData(): array
	{
		return [
			'sliderCode' => self::LOCKED_SLIDER_CODE,
			'isLocked' => $this->config['isLocked'] ?? false,
			'moduleId' => $this->config['moduleId'] ?? null,
			'category' => $this->config['category'] ?? null,
			'contextId' => $this->config['contextId'] ?? null,
		];
	}

	private function isRegionAvailable(): bool
	{
		$region = Application::getInstance()->getLicense()->getRegion();
		if ($region === null)
		{
			return false;
		}

		return !in_array(mb_strtolower($region), self::REGION_BLACKLIST, true);
	}
}
