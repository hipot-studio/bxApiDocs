<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action;

use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\GridAccessServiceInterface;

class DeleteAction implements Action
{
	public function __construct(
		private readonly int $currentUserId = 0,
		private readonly ?GridAccessServiceInterface $accessService = null,
		private readonly string $text = '',
		private readonly Type $entityType = Type::Collab,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'delete';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);

		if (!$this->canDelete($rawFields, $id))
		{
			return null;
		}

		$entityType = Type::tryFrom($rawFields['TYPE'] ?? '') ?? $this->entityType;
		if ($entityType && in_array($entityType, [Type::Collab, Type::Scrum], true))
		{
			return [
				'text' => $this->text,
				'onclick' => ProjectListControllerActionBuilder::buildDeleteAction($id, $entityType),
			];
		}

		$href = (string)($rawFields['DELETE_URL'] ?? '');
		if ($href === '')
		{
			$href = '/workgroups/group/' . $id . '/delete/';
		}

		return [
			'text' => $this->text,
			'href' => $href,
		];
	}

	private function canDelete(array $rawFields, int $id): bool
	{
		if (isset($rawFields['CAN_DELETE']))
		{
			return (bool)$rawFields['CAN_DELETE'];
		}

		return $this->accessService !== null
			&& $this->accessService->canDelete($this->currentUserId, $id);
	}
}
