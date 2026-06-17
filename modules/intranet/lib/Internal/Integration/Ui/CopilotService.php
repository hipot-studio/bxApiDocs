<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Ui;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

class CopilotService
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('ui');
	}

	public function getName(): string
	{
		return
			$this->isAvailable
			? (new CopilotNameService())->getCopilotName()
			: ''
		;
	}

	public static function shouldShowInLeftMenu(): bool
	{
		return Application::getInstance()->getLicense()->getRegion() !== 'cn';
	}
}