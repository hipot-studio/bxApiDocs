<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Collab\Control\Command\ValueObject\CollabSiteIds;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectUpdateFieldsException;
use CSocNetGroup;

class UpdateGroupFields implements HandlerInterface
{
	/**
	 * @throws ProjectUpdateFieldsException
	 */
	public function __invoke(Workgroup $group): void
	{
		$fields = $this->prepareFields($group);
		if (empty($fields))
		{
			return;
		}

		$updateResult = CSocNetGroup::Update($group->getId(), $fields);
		if ($updateResult === false)
		{
			throw new ProjectUpdateFieldsException('Unable to update group fields');
		}
	}

	protected function prepareFields(Workgroup $group): array
	{
		$fieldsToUpdate = [];

		$newOpened = $group->isOpened() && $group->isVisible();
		if ($group->isOpened() !== $newOpened)
		{
			$fieldsToUpdate['OPENED'] = $newOpened ? 'Y' : 'N';
		}

		if ($group->isVisible() !== $newOpened)
		{
			$fieldsToUpdate['VISIBLE'] = $newOpened ? 'Y' : 'N';
		}

		if ($group->getType() !== Type::Collab)
		{
			$fieldsToUpdate['TYPE'] = Type::Collab->value;
			$fieldsToUpdate['SITE_ID'] = CollabSiteIds::createWithDefaultValue()->getValue();
		}

		if ((string)($group->getFields()['PROJECT'] ?? 'N') !== 'Y')
		{
			$fieldsToUpdate['PROJECT'] = 'Y';
		}

		return $fieldsToUpdate;
	}
}
