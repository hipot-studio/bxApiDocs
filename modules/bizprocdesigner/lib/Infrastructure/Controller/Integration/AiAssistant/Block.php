<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Controller\Integration\AiAssistant;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\UserBlockService;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Main\Request;

class Block extends JsonController
{
	private readonly UserBlockService $userSelectedBlockService;

	public function __construct(
		Request $request = null,
		?UserBlockService $userSelectedBlockService = null,
	)
	{
		parent::__construct($request);

		Loader::requireModule('bizproc');

		$this->userSelectedBlockService = $userSelectedBlockService ?? Container::getAiAssistantUserBlockService();
	}

	public function setAction(?string $blockId = null): void
	{
		$userId = (int)CurrentUser::get()->getId();

		$this->userSelectedBlockService->set($userId, $blockId);
	}
}