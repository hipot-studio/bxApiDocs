<?php declare(strict_types=1);

namespace Bitrix\AI\Controller;

use Bitrix\AI\Exception\ErrorCollectionException;
use Bitrix\AI\Parameter\DefaultParameter;
use Bitrix\AI\ShareRole\Service\GridRole\PartGridRoleService;
use Bitrix\AI\ShareRole\Events\EditAnalyticEvent;
use Bitrix\AI\ShareRole\Events\Enums\Status;
use Bitrix\AI\ShareRole\Events\SaveAnalyticEvent;
use Bitrix\AI\ShareRole\Request\ChangeRequest;
use Bitrix\AI\ShareRole\Request\GetRoleDataByCodeRequest;
use Bitrix\AI\ShareRole\Request\CreateRequest;
use Bitrix\AI\ShareRole\Service\RoleService;
use Bitrix\AI\ShareRole\Service\ShareService;
use Bitrix\AI\ShareRole\Service\OwnerService;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Loader;
use Bitrix\Main\Engine\Controller;
use Bitrix\AI\Controller\ActionFilter\CheckCopilotAccess;

class ShareRole extends Controller
{
	public function configureActions(): array
	{
		return [
			'showAvatar' => [
				'-prefilters' => [Csrf::class],
			],
		];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new DefaultParameter()
		];
	}

	public function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		if (Loader::includeModule('intranet') && Loader::includeModule('extranet'))
		{
			$filters[] = new CheckCopilotAccess();
		}

		return $filters;
	}

	protected function runProcessingThrowable(\Throwable $throwable): void
	{
		if ($throwable instanceof ErrorCollectionException)
		{
			foreach ($throwable->getCollection() as $error)
			{
				$this->errorCollection[] = $error;
			}

			return;
		}

		parent::runProcessingThrowable($throwable);
	}

	/**
	 * Creates a custom role and shares it by access codes.
	 * Request fields: roleTitle, roleDescription, roleText, roleAvatar (file), accessCodes.
	 *
	 * @param CreateRequest $request
	 * @param RoleService $roleService
	 * @param ShareService $shareService
	 * @return array{code?: string} Code of the created role.
	 */
	public function createAction(
		CreateRequest $request,
		RoleService $roleService,
		ShareService $shareService,
	): array
	{
		$requestDTO = $request->getData($this->request, $this->getCurrentUser());
		$roleCreateResult = $roleService->createRole($requestDTO);
		$event = new SaveAnalyticEvent($requestDTO->shareType);

		if (!$roleCreateResult->isSuccess())
		{
			$event->send(Status::Error, $requestDTO->userCreatorId);
			$this->addErrors($roleCreateResult->getErrors());
			return [];
		}

		$requestDTO->roleId = $roleCreateResult->getId();

		$shareCreateResult = $shareService->create($requestDTO);

		if (!$shareCreateResult->isSuccess())
		{
			$event->send(Status::Error, $requestDTO->userCreatorId);
			$this->addErrors($roleCreateResult->getErrors());
			return [];
		}

		$event->send(Status::Success, $requestDTO->userCreatorId);

		return [
			'code' => $requestDTO->roleCode,
		];
	}

	/**
	 * Updates a custom role and replaces its sharing. Only the role author may change it.
	 * Request fields: roleCode and the fields of createAction.
	 *
	 * @param ChangeRequest $request
	 * @param RoleService $roleService
	 * @param ShareService $shareService
	 * @param OwnerService $ownerService
	 * @return void
	 */
	public function changeAction(
		ChangeRequest $request,
		RoleService $roleService,
		ShareService $shareService,
		OwnerService $ownerService,
	): void
	{
		$requestDTO = $request->getData($this->request, $this->getCurrentUser());
		$updateResult = $roleService->saveRole($requestDTO);
		$event = new EditAnalyticEvent($requestDTO->shareType);

		if (!$updateResult->isSuccess())
		{
			$event->send(Status::Error, $requestDTO->userCreatorId);

			return;
		}

		$shareService->deleteSharingForChange($requestDTO->roleId);
		$createResult = $shareService->create($requestDTO);

		if (!$createResult->isSuccess())
		{
			$event->send(Status::Error, $requestDTO->userCreatorId);

			return;
		}

		$event->send(Status::Success, $requestDTO->userCreatorId);
	}

	/**
	 * Returns custom role data for the edit form. Requires share access to the role.
	 *
	 * @param GetRoleDataByCodeRequest $request
	 * @param RoleService $roleService
	 * @return array{role: \Bitrix\AI\ShareRole\Dto\RoleForUpdateDto|null}
	 */
	public function getRoleByCodeForUpdateAction(
		GetRoleDataByCodeRequest $request,
		RoleService $roleService,
	): array
	{
		$requestDTO = $request->getData($this->request, $this->getCurrentUser());

		return [
			'role' => $roleService->getRoleByIdForUpdate($requestDTO->roleId),
		];
	}

	/**
	 * Returned shares for role by role code
	 *
	 * @param GetRoleDataByCodeRequest $request
	 * @param PartGridRoleService $partGridRoleService
	 * @return array
	 * @throws \Exception
	 */
	public function getShareForRoleAction(
		GetRoleDataByCodeRequest $request,
		PartGridRoleService $partGridRoleService
	): array
	{
		$requestDTO = $request->getData($this->request, $this->getCurrentUser());

		return [
			'list' => $partGridRoleService->getShare($requestDTO->roleId, $requestDTO->userId)
		];
	}

	/**
	 * Outputs the role avatar image inline; falls back to the default avatar. CSRF check is disabled
	 * so that the URL can be used in img tags.
	 *
	 * @param RoleService $roleService
	 * @param int $roleId
	 * @param string $avatarSize One of \Bitrix\AI\Enum\RoleAvatarSize values.
	 * @return BFile
	 */
	public function showAvatarAction(
		RoleService $roleService,
		int $roleId,
		string $avatarSize
	): BFile
	{
		return $roleService->getAvatarByRoleId($roleId, $avatarSize);
	}
}
