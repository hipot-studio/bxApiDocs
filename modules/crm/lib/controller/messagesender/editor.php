<?php

namespace Bitrix\Crm\Controller\MessageSender;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\MessageSender\CustomTemplate\CrmZone;
use Bitrix\Crm\MessageSender\CustomTemplate\CrmZoneProvider;
use Bitrix\Crm\MessageSender\UI\Factory;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\Editor as MessageEditor;
use Bitrix\MessageService\Public\UI\MessageEditor\Scene\NullScene;

final class Editor extends Base
{
	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::AJAX);
		$filters[] = new ActionFilter\ContentType([ ActionFilter\ContentType::JSON ]);
		$filters[] = new class extends ActionFilter\Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (Container::getInstance()->getUserPermissions()->messageSender()->canSendFromSomeItemsInCrmOrAutomatedSolutions())
				{
					return null;
				}

				$this->addError(ErrorCode::getAccessDeniedError());

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
		};
		$filters[] = new class extends ActionFilter\Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (Loader::includeModule('messageservice'))
				{
					return null;
				}

				$this->addError(ErrorCode::getModuleNotInstalledError('messageservice'));

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
		};

		return $filters;
	}

	// todo move to crm.activity.sms ?
	public function loadAction(
		string $sceneId,
		?array $customData = null,
	): ?array
	{
		$factory = Factory::getInstance();

		$scene = empty($sceneId) ? new NullScene() : $factory->getScene($sceneId);
		if (!$scene)
		{
			$this->addError(new Error('Scene not found', 'SCENE_NOT_FOUND'));

			return null;
		}

		$editor = $factory->createEditor(
			$scene,
			new Context(customData: $customData),
		);

		return [
			'editor' => $editor,
		];
	}

	/**
	 * AJAX entry point used by the CRM-flavoured custom-template form.
	 *
	 * Module-wide CRM permission is enforced by this controller's
	 * `canSendFromSomeItemsInCrmOrAutomatedSolutions` prefilter (see
	 * {@see self::getDefaultPreFilters()}); on top of it this action runs the
	 * fine-grained per-binding gate ({@see CrmZoneProvider::canReadTemplates()})
	 * before resolving the binding description, mirroring the same gate that guards
	 * the messageservice CUD handlers. Errors are surfaced through `addError(...)`
	 * so the JS client gets the standard `{ errors: [...] }` envelope.
	 *
	 * @return array{editor: MessageEditor, bindingDescription: array{sceneLabel: string, targetLabel: string}, bindingLabels: list<string>}|null
	 */
	public function loadCustomTemplateAction(
		CurrentUser $currentUser,
		string $scene,
		string $targetId,
	): ?array
	{
		if (!CrmZone::isSupportedByMessageService())
		{
			$this->addError(ErrorCode::getModuleNotInstalledError('messageservice'));

			return null;
		}

		$userId = (int)$currentUser->getId();

		$editor = Factory::getInstance()->createCustomTemplateEditor($scene, $targetId, $userId);
		if ($editor === null)
		{
			$this->addError(new Error('Invalid scene or targetId', 'BAD_PARAMS'));

			return null;
		}

		$zoneProvider = ServiceLocator::getInstance()->get(CrmZoneProvider::class);
		$binding = new TemplateBinding(CrmZone::ID, $scene, $targetId);

		// The controller prefilter only proves a coarse "can send from some CRM items" right;
		// describeBinding() below resolves a category name, so gate it on the per-binding right
		// to avoid leaking funnel names of categories the user has no access to.
		if (!$zoneProvider->canReadTemplates($userId, $binding))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$description = $zoneProvider->describeBinding($binding);

		return [
			'editor' => $editor,
			'bindingDescription' => [
				'sceneLabel' => $description->sceneLabel,
				'targetLabel' => $description->targetLabel,
			],
			'bindingLabels' => $zoneProvider->getEditorBindingLabels($binding),
		];
	}
}
