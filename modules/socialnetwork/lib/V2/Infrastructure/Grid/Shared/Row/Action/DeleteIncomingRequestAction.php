<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action;

use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;

class DeleteIncomingRequestAction implements Action
{
	public function __construct(
		private readonly string $text = '',
		private readonly string $entityType = Type::Project->value,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'deleteIncomingRequest';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	public function getControl(array $rawFields): ?array
	{
		$canDelete = (bool)($rawFields['CAN_DELETE_INCOMING_REQUEST'] ?? false);
		if (!$canDelete)
		{
			return null;
		}

		$id = (int)($rawFields['ID'] ?? 0);
		if ($id <= 0)
		{
			return null;
		}

		return [
			'text' => $this->text,
			'onclick' => ProjectListControllerActionBuilder::buildRowAction(
				action: 'deleteIncomingRequest',
				entityId: $id,
				entityType: is_string($rawFields['TYPE'] ?? null) ? $rawFields['TYPE'] : $this->entityType,
			),
		];
	}
}
