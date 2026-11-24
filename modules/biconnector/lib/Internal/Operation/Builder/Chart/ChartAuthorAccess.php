<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetOwnership;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class ChartAuthorAccess
{
	public const REQUIRED_MESSAGE = 'Only the confirmed chart author or an administrator can manage this chart.';

	private readonly \Closure $accessControllerFactory;
	private readonly \Closure $rightsEnabled;
	private readonly SupersetOwnership $ownership;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 * @param (\Closure(): bool)|null $rightsEnabled
	 */
	public function __construct(
		?\Closure $accessControllerFactory = null,
		?\Closure $rightsEnabled = null,
	)
	{
		$this->ownership = new SupersetOwnership();
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId);
		$this->rightsEnabled = $rightsEnabled ?? static fn(): bool => !Loader::includeModule('bitrix24')
			|| \Bitrix\Bitrix24\Feature::isFeatureEnabled(Feature::BI_BUILDER_RIGHTS);
	}

	public function mayManageOrphan(array $owners, Actor $actor): bool
	{
		if (
			in_array(1, array_map('intval', \CUser::GetUserGroup($actor->userId)), true)
			|| (Loader::includeModule('bitrix24') && \CBitrix24::IsPortalAdmin($actor->userId))
		)
		{
			return true;
		}
		if ($this->ownership->check($actor->userId, $owners))
		{
			return true;
		}

		return ($this->rightsEnabled)()
			&& ($this->accessControllerFactory)($actor->userId)->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_RIGHTS);
	}

	public function check(array $owners, Actor $actor): Result
	{
		return $this->mayManageOrphan($owners, $actor)
			? new Result()
			: Refusal::error(FailureCode::AccessDenied, self::REQUIRED_MESSAGE)
				->setData(['reason' => 'chart_author_required'])
			;
	}
}
