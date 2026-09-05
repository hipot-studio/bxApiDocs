<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller;

use Bitrix\Disk\Internal\Service\HtmlViewerService;
use Bitrix\Disk\Internals\Engine\ActionFilter\CheckReadPermission;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;

/**
 * Keeps the html viewer speaking html: the endpoint is closed before the action runs — by
 * CheckReadPermission, or by an id naming nothing — and the engine would answer such a refusal with a
 * json envelope, which the viewer iframe renders as page text. The checks themselves stay where they are.
 *
 * Shared by the controllers serving showHtml (file, version, attached object). Whatever the parent
 * controller already answers — the disk base controller replaces a csrf refusal with its own
 * response — passes through untouched: only a null result of that very action is substituted.
 */
trait HtmlViewerRefusalResponse
{
	private bool $htmlViewerArgumentsUnresolved = false;

	/**
	 * Arguments of the html action are resolved here, ahead of the filter chain, because the engine binds
	 * them once CheckReadPermission asks for them and lets a BinderArgumentException out of run() — past
	 * processAfterAction, the only place where the answer can still be substituted. A deleted object, or
	 * an id resolving to nothing, is refused here instead and answered after the action as any other
	 * refusal. The binder keeps the failed binding, so the filters that follow read the same empty list
	 * of arguments and decide nothing on it.
	 */
	protected function processBeforeAction(Action $action)
	{
		$allowed = parent::processBeforeAction($action);
		if (!$this->isHtmlViewerAction($action))
		{
			return $allowed;
		}

		try
		{
			$action->getArguments();
		}
		catch (BinderArgumentException)
		{
			$this->htmlViewerArgumentsUnresolved = true;

			return false;
		}

		return $allowed;
	}

	protected function processAfterAction(Action $action, $result)
	{
		$result = parent::processAfterAction($action, $result);

		$isRefusedHtml =
			$result === null
			&& $this->isHtmlViewerAction($action)
			&& (
				$this->htmlViewerArgumentsUnresolved
				|| $this->getErrorByCode(CheckReadPermission::ERROR_COULD_NOT_READ_OBJECT) !== null
			)
		;

		return $isRefusedHtml
			? ServiceLocator::getInstance()->get(HtmlViewerService::class)->unavailableResponse()
			: $result
		;
	}

	private function isHtmlViewerAction(Action $action): bool
	{
		return strcasecmp($action->getName(), 'showHtml') === 0;
	}
}
