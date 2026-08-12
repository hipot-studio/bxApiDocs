<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Mixins;

use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Main\Localization\Loc;

trait ChecksResolvedTargetAccessTrait
{
	protected function canUpdateResolvedTarget(array $documentId): bool
	{
		return $this->checkResolvedTargetAccess($documentId, 'canUpdate');
	}

	protected function canReadResolvedTarget(array $documentId): bool
	{
		return $this->checkResolvedTargetAccess($documentId, 'canRead');
	}

	protected function canDeleteResolvedTarget(array $documentId): bool
	{
		return $this->checkResolvedTargetAccess($documentId, 'canDelete');
	}

	protected function logResolvedTargetAccessDenied(): void
	{
		/** @var \CBPActivity $this */
		$this->WriteToTrackingService(
			$this->getResolvedTargetAccessDeniedMessage(),
			0,
			\CBPTrackingType::Error,
		);
	}

	protected function getResolvedTargetAccessDeniedMessage(): string
	{
		return (string)Loc::getMessage('BPA_NODE_FILTER_NO_PERMISSIONS_RESOLVED');
	}

	protected function getResolvedTargetAccessActorId(): int
	{
		return $this->getRootStartedByUserId();
	}

	protected function getRootStartedByUserId(): int
	{
		/** @var \CBPActivity $this */
		$rootActivity = $this->getRootActivity();

		return (int)\CBPHelper::stripUserPrefix(
			(string)($rootActivity->{\CBPDocument::PARAM_TAGRET_USER} ?? '')
		);
	}

	/**
	 * Temporarily disabled: crm and tasks activities must behave as before the guard was introduced.
	 */
	protected function isResolvedTargetAccessCheckEnabled(): bool
	{
		return false;
	}

	private function checkResolvedTargetAccess(array $documentId, string $action): bool
	{
		if (!$this->isResolvedTargetAccessCheckEnabled())
		{
			return true;
		}

		/** @var \CBPActivity $this */
		if ($this->getRootStartedByUserId() <= 0)
		{
			return true;
		}

		$guard = Container::instance()->getTargetDocumentAccessGuardRegistry()->resolve($this);
		if ($guard === null)
		{
			return true;
		}

		$rootDocumentId = $this->getRootActivity()->getDocumentId();

		$check = $guard->{$action}(
			is_array($rootDocumentId) ? $rootDocumentId : [],
			$documentId,
			$this->getResolvedTargetAccessActorId(),
		);

		return $check !== false;
	}
}
