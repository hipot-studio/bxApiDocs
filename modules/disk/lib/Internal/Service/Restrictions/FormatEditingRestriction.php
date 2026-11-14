<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;

/**
 * Tells whether an existing file may still be opened for editing under the format policy.
 *
 * Editing ends in a write back to the same file, so the verdict is the write one asked by the name
 * alone: the paths that open a document read no content and pay for no extra look into the storage.
 * Viewing and downloading are left alone - only the way into the editor closes.
 *
 * The question is asked as an upload, not as an editing session, and that is the point: a session
 * already open keeps saving its document even when the policy came later
 * ({@see FormatPolicyService::isSessionStartedBeforePolicy()}), but opening one is a decision of its
 * own and gets no such amnesty. So the work of a user who was already editing is not lost, while a
 * document of a restricted format opens as a view from then on.
 */
class FormatEditingRestriction
{
	/**
	 * @param FormatPolicyService|null $policyService Policy service; the container answers when it is omitted.
	 */
	public function __construct(
		private ?FormatPolicyService $policyService = null,
	)
	{
	}

	/**
	 * Tells whether a file with this name is left to viewing only for the user opening it: the
	 * editing ends in a write of that user, so it is their policy that decides.
	 *
	 * @param string|null $fileName Name of the file being opened.
	 * @param int|null $userId User opening the file; the current user when omitted.
	 * @return bool
	 */
	public function isRestricted(?string $fileName, ?int $userId = null): bool
	{
		if ($fileName === null || $fileName === '')
		{
			return false;
		}

		return !$this->getPolicyService()
			->isAllowed($fileName, WriteOperationContext::UserUpload, $userId ?? $this->getCurrentUserId())
			->isSuccess()
		;
	}

	/**
	 * The text the interface shows over the inactive editing control.
	 *
	 * @return string
	 */
	public function getHint(): string
	{
		return (string)Loc::getMessage('DISK_RESTRICTIONS_EDITING_RESTRICTED_HINT');
	}

	protected function getCurrentUserId(): ?int
	{
		$userId = CurrentUser::get()->getId();

		return $userId === null ? null : (int)$userId;
	}

	/**
	 * The service is resolved on the first question asked about a name: the paths that only show the
	 * hint, and the ones answered by an empty name, reach no policy at all.
	 */
	protected function getPolicyService(): FormatPolicyService
	{
		return $this->policyService ??= ServiceLocator::getInstance()->get('disk.formatPolicyService');
	}
}
