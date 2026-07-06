<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserGroupTable;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;

final class ForceCreateIncomingWebhookCommandHandler extends AbstractCreateIncomingWebhookCommandHandler
{
	private const ADMIN_GROUP_ID = 1;

	public function __invoke(ForceCreateIncomingWebhookCommand $command): IncomingWebhook
	{
		$this->requireUser($command->userId);
		$scopes = $this->normalizeScopes($command->scopes);

		return $this->createIntegration(
			initiatorUserId: $this->getAdminUserId(),
			ownerUserId: $command->userId,
			title: $command->title,
			scopes: $scopes,
			attributes: $command->attributes,
		);
	}

	private function getAdminUserId(): int
	{
		$now = new SqlExpression(
			Application::getConnection()->getSqlHelper()->getCurrentDateTimeFunction()
		);

		$row = UserGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', self::ADMIN_GROUP_ID)
			->where('USER.ACTIVE', 'Y')
			->where(
				Query::filter()
					->logic('or')
					->whereNull('DATE_ACTIVE_FROM')
					->where('DATE_ACTIVE_FROM', '<=', $now)
			)
			->where(
				Query::filter()
					->logic('or')
					->whereNull('DATE_ACTIVE_TO')
					->where('DATE_ACTIVE_TO', '>=', $now)
			)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit(1)
			->exec()
			->fetch();

		if (empty($row['USER_ID']))
		{
			throw new ObjectNotFoundException('No active admin user found on this portal');
		}

		return (int)$row['USER_ID'];
	}
}
