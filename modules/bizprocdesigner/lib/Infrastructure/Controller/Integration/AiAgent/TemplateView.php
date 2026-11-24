<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller\Integration\AiAgent;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Service\AiAgent\SystemTemplateViewService;
use Bitrix\BizprocDesigner\Internal\Service\Activity\SettingsControlsBuilder;
use Bitrix\BizprocDesigner\Internal\Service\SystemTemplateView\SystemTemplateSchemeReader;
use Bitrix\BizprocDesigner\Internal\Service\SystemTemplateView\SystemTemplateViewServiceProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Main\Request;

/**
 * The read-only surface of the editor for a system AI-agent template: its scheme and the settings form of
 * one of its nodes. Unlike {@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Diagram::getAction()} it
 * creates no template, reads no draft, pilot, lease or version history, subscribes to no discussion channel
 * and hands out no right to publish.
 *
 * The id of the request is the id of a grid row - a system template or a copy launched from one - and only
 * the system template it resolves to is ever read; every refusal is the single accessDenied of
 * {@see SystemTemplateViewService}.
 */
class TemplateView extends JsonController
{
	private const MODE = 'view';
	// The value of SystemTemplateViewService::ERROR_CODE_ACCESS_DENIED, readable while bizproc lacks the class.
	private const ERROR_CODE_ACCESS_DENIED = 'accessDenied';

	private ?SystemTemplateViewService $viewService;
	private ?SystemTemplateSchemeReader $schemeReader;

	public function __construct(
		?Request $request = null,
		?SystemTemplateViewService $viewService = null,
		?SystemTemplateSchemeReader $schemeReader = null,
	)
	{
		parent::__construct($request);

		$this->viewService = $viewService;
		$this->schemeReader = $schemeReader;
	}

	protected function init(): void
	{
		parent::init();

		Loader::requireModule('bizproc');
	}

	public function getAction(int $templateId): ?array
	{
		$systemTemplateId = $this->resolveSystemTemplateId($templateId);
		if ($systemTemplateId === null)
		{
			return null;
		}

		$scheme = $this->schemeReader()->read($systemTemplateId);
		if ($scheme === null)
		{
			$this->denyAccess();

			return null;
		}

		return array_merge(
			[
				'mode' => self::MODE,
				'templateId' => $systemTemplateId,
			],
			$scheme,
		);
	}

	/**
	 * Only the two values below are taken from the request: the scheme, the node and its values are read
	 * from the stored row, so a client cannot have a form built over a scheme of its own.
	 */
	public function getNodeSettingsControlsAction(int $templateId, string $activityName): ?array
	{
		$systemTemplateId = $this->resolveSystemTemplateId($templateId);
		if ($systemTemplateId === null)
		{
			return null;
		}

		$context = $this->schemeReader()->readActivityContext($systemTemplateId, $activityName);
		if ($context === null)
		{
			$this->denyAccess();

			return null;
		}

		// The editor builds the form of an action of a complex node without the title and the comment, which
		// belong to the node; the view decides it from the stored scheme rather than from the client.
		$options = $context['isComplexNodeAction']
			? ['hideTitle' => true, 'hideEditorComment' => true]
			: []
		;

		// The saved project context is read under the right to edit the template, which the view does not
		// grant, so the controls of a project show the stored value as it is.
		return $this->settingsControlsBuilder()->build(
			$context['documentType'],
			$context['activity'],
			$context['workflow'],
			$options,
		);
	}

	protected function settingsControlsBuilder(): SettingsControlsBuilder
	{
		return new SettingsControlsBuilder();
	}

	private function resolveSystemTemplateId(int $rowTemplateId): ?int
	{
		$viewService = $this->viewService();
		if ($viewService === null)
		{
			$this->denyAccess();

			return null;
		}

		$result = $viewService->resolve($rowTemplateId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return (int)$result->getData()[SystemTemplateViewService::SYSTEM_TEMPLATE_ID];
	}

	/**
	 * A refusal outside the admission - no view service in bizproc, no such template or no such node - reads as
	 * any other refusal.
	 */
	private function denyAccess(): void
	{
		$this->addError(
			ErrorMessage::ACCESS_DENIED->getError([], self::ERROR_CODE_ACCESS_DENIED),
		);
	}

	private function viewService(): ?SystemTemplateViewService
	{
		return $this->viewService ??= ServiceLocator::getInstance()->get(SystemTemplateViewServiceProvider::class)->get();
	}

	private function schemeReader(): SystemTemplateSchemeReader
	{
		return $this->schemeReader ??= new SystemTemplateSchemeReader();
	}
}
