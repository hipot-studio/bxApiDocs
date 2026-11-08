<?php

namespace Bitrix\Crm\Controller\Timeline;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\TimelineCommentTextEditor;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Comment\EditorConfigProvider;
use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Main\Engine\ActionFilter\Scope;
use CCrmOwnerType;

class CommentEditor extends Base
{
	public function configureActions(): array
	{
		return ['getConfig' => ['+prefilters' => [new Scope(Scope::AJAX)]]];
	}

	public function getConfigAction(int $ownerTypeId, int $ownerId, int $commentId = 0): ?array
	{
		if (!$this->isFeatureEnabled())
		{
			$this->addError(ErrorCode::getFeatureDisabledError());

			return null;
		}

		if ($ownerId <= 0 || !CCrmOwnerType::IsDefined($ownerTypeId))
		{
			$this->addError(ErrorCode::getOwnerNotFoundError());

			return null;
		}

		if (!Container::getInstance()->getUserPermissions()->item()->canUpdate($ownerTypeId, $ownerId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		if ($commentId < 0)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		if ($commentId > 0)
		{
			$comment = TimelineTable::getRow([
				'select' => ['ID'],
				'filter' => ['=ID' => $commentId, '=TYPE_ID' => TimelineType::COMMENT],
			]);
			$binding = TimelineBindingTable::getRow([
				'select' => ['OWNER_ID'],
				'filter' => [
					'=OWNER_ID' => $commentId,
					'=ENTITY_TYPE_ID' => $ownerTypeId,
					'=ENTITY_ID' => $ownerId,
				],
			]);
			if ($comment === null || $binding === null)
			{
				$this->addError(ErrorCode::getNotFoundError());

				return null;
			}
		}

		return $this->createConfigProvider()->getConfig($commentId);
	}

	protected function isFeatureEnabled(): bool
	{
		return Feature::enabled(TimelineCommentTextEditor::class);
	}

	protected function createConfigProvider(): EditorConfigProvider
	{
		return new EditorConfigProvider();
	}
}
