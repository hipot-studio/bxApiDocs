<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action;

use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\GridAccessServiceInterface;

class ArchiveAction implements Action
{
	public function __construct(
		private readonly int $currentUserId = 0,
		private readonly ?GridAccessServiceInterface $accessService = null,
		private readonly string $text = '',
		private readonly string $entityType = Type::Project->value,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'addToArchive';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	public function getControl(array $rawFields): ?array
	{
		$closed = (bool)($rawFields['CLOSED'] ?? false);
		if ($closed)
		{
			return null;
		}

		$id = (int)($rawFields['ID'] ?? 0);

		if (!$this->canModify($rawFields, $id))
		{
			return null;
		}

		return [
			'text' => $this->text,
			'onclick' => ProjectListControllerActionBuilder::buildRowAction(
				action: 'addToArchive',
				entityId: $id,
				entityType: is_string($rawFields['TYPE'] ?? null) ? $rawFields['TYPE'] : $this->entityType,
			),
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
