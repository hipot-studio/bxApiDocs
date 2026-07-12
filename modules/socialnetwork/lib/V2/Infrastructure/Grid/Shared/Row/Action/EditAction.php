<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action;

use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Helper\Feature;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\GridAccessServiceInterface;

class EditAction implements Action
{
	public function __construct(
		private readonly int $currentUserId = 0,
		private readonly ?GridAccessServiceInterface $accessService = null,
		private readonly string $text = '',
	)
	{
	}

	public static function getId(): ?string
	{
		return 'edit';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);

		if (!$this->canModify($rawFields, $id))
		{
			return null;
		}

		$entityType = is_string($rawFields['TYPE'] ?? null)
			? Type::tryFrom($rawFields['TYPE'])
			: null;

		if ($entityType === Type::Scrum)
		{
			$href = (string)($rawFields['EDIT_URL'] ?? '');
			if ($href === '')
			{
				$href = '/workgroups/group/' . $id . '/edit/';
			}

			return [
				'text' => $this->text,
				'href' => $href,
			];
		}

		$chatId = (int)($rawFields['CHAT_ID'] ?? 0);
		if ($chatId <= 0)
		{
			return null;
		}

		$isRestricted = !Feature::isFeatureEnabled(Feature::PROJECTS_GROUPS) && !Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS);
		if ($isRestricted)
		{
			return [
				'text' => $this->text,
				'onclick' => "BX.UI.FeaturePromotersRegistry.getPromoter({ featureId: 'socialnetwork_projects_groups' }).show();",
			];
		}

		return [
			'text' => $this->text,
			'onclick' => sprintf("BX.Messenger.Public.openChatUpdate('chat%d')", $chatId),
		];
	}

	private function canModify(array $rawFields, int $id): bool
	{
		if (isset($rawFields['CAN_MODIFY']))
		{
			return (bool)$rawFields['CAN_MODIFY'];
		}

		return $this->accessService !== null
			&& $this->accessService->canUpdate($this->currentUserId, $id);
	}
}
