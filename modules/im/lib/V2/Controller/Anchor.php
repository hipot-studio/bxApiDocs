<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Anchor\AnchorCollection;
use Bitrix\Im\V2\Anchor\DI\AnchorContainer;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Engine\CurrentUser;

final class Anchor extends BaseController
{
	private AnchorContainer $anchorContainer;

	private int $userId;

	/**
	 * @restMethod im.v2.Anchor.read
	 */
	public function readAction(MessageCollection $messages): ?bool
	{
		$readService = $this->anchorContainer->getReadService()->withContextUser($this->userId);

		$result = $readService->read($messages->getIds());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * The whole list of anchors of the calling user, without pagination.
	 * The anchor_enabled option stops new anchors only and does not limit this list.
	 *
	 * @restMethod im.v2.Anchor.list
	 */
	public function listAction(): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$anchors = $this->anchorContainer->getAnchorProvider()->getUserAnchors($userId);

		return [AnchorCollection::getRestEntityName() => $anchors];
	}

	protected function init(): void
	{
		parent::init();

		$this->anchorContainer = AnchorContainer::getInstance();
		$this->userId = (int)CurrentUser::get()->getId();
	}
}