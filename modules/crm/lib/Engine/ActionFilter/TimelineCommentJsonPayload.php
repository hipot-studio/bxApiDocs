<?php

namespace Bitrix\Crm\Engine\ActionFilter;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\TimelineCommentTextEditor;
use Bitrix\Crm\Timeline\CommentController;
use Bitrix\Disk\Driver;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\ActionFilter\ContentType;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;

final class TimelineCommentJsonPayload extends Base
{
	public function listAllowedScopes(): array
	{
		return [
			Controller::SCOPE_AJAX,
		];
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$controller = $this->getAction()->getController();
		$request = $controller->getRequest();
		if ($request->getHeaders()->getContentType() !== ContentType::JSON)
		{
			return null;
		}

		if (!Feature::enabled(TimelineCommentTextEditor::class))
		{
			return null;
		}

		$payload = $request->getJsonList()->toArray();
		if (!is_array($payload))
		{
			return null;
		}

		$parameters = [];
		foreach ($payload as $key => $value)
		{
			if ($key && !is_numeric($key))
			{
				$parameters[$key] = $value;
			}
		}

		if (!$parameters)
		{
			return null;
		}

		// The same source as ContentType adds for JSON: that filter is final and rejects any other content type
		$controller->setSourceParametersList([...$controller->getSourceParametersList(), $parameters]);

		$this->passAllowEditToPost($payload);

		return null;
	}

	// Disk reads the "allow edit" flags of attachments from POST of the context request only
	private function passAllowEditToPost(array $payload): void
	{
		if (!Loader::includeModule('disk'))
		{
			return;
		}

		$fieldName = Driver::getInstance()->getUserFieldManager()->getInputNameForAllowEditByEntityType(
			CommentController::UF_FIELD_NAME,
		);
		$allowEdit = $payload[$fieldName] ?? null;
		if (!is_array($allowEdit) || empty($allowEdit))
		{
			return;
		}

		$request = Application::getInstance()->getContext()->getRequest();
		if ($request instanceof HttpRequest)
		{
			$request->addFilter(new TimelineCommentAllowEditRequestFilter($fieldName, $allowEdit));
		}
	}
}
