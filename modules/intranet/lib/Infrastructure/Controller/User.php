<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Infrastructure\Controller\ActionFilter\RequiredModule;
use Bitrix\Intranet\Infrastructure\Controller\AutoWire\DepartmentParameterTrait;
use Bitrix\Intranet\Integration\HumanResources\DepartmentAssigner;
use Bitrix\Intranet\Infrastructure\Controller\AutoWire\UserParameterTrait;
use Bitrix\Intranet\Internal\Integration\Bitrix24\Integrator\IntegratorGroup;
use Bitrix\Intranet\Internal\Integration\Bitrix24\Integrator\PartnerInfo;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\Internal\Entity\FireWizardConfig;
use Bitrix\Intranet\User\Access\Model\TargetUserModel;
use Bitrix\Intranet\User\Access\UserAccessController;
use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\Intranet\User\Command\DeleteOrFireUserCommand;
use Bitrix\Intranet\User\Command\DeleteUserCommand;
use Bitrix\Intranet\User\Command\FireUserCommand;
use Bitrix\Intranet\User\Command\RestoreUserCommand;
use Bitrix\Main\Access\Exception\AccessException;
use Bitrix\Main\Access\Exception\UnknownActionException;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Rest\Public\Command\IncomingWebhook\ChangeOwnerCommand;

class User extends \Bitrix\Main\Engine\Controller
{
	use UserParameterTrait;
	use DepartmentParameterTrait;

	protected function getDefaultPreFilters()
	{
		return [
			...parent::getDefaultPreFilters(),
			new \Bitrix\Intranet\ActionFilter\IntranetUser(),
		];
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			$this->createDepartmentCollectionParameter(),
			$this->createUserParameter(),
			new ExactParameter(
				FireWizardConfig::class,
				'fireWizardConfig',
				function($className, array $options = []): FireWizardConfig {
					$moveWebhooksToSystemUser = filter_var(
						$options['moveWebhooksToSystemUser'] ?? false,
						FILTER_VALIDATE_BOOLEAN,
					);

					return new FireWizardConfig($moveWebhooksToSystemUser);
				},
			),
		];
	}

	public function configureActions(): array
	{
		return [
			'removeIntegratorRights' => [
				'+prefilters' => [
					new \Bitrix\Intranet\ActionFilter\AdminUser(),
					new RequiredModule(['bitrix24']),
				],
			],
		];
	}

	/**
	 * @ajaxAction intranet.v2.User.deleteOrFire
	 * @param \Bitrix\Intranet\Entity\User $user
	 * @return bool
	 * @throws AccessException
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function deleteOrFireAction(\Bitrix\Intranet\Entity\User $user): bool
	{
		$access = UserAccessController::createByDefault();
		$targetUser = TargetUserModel::createFromUserEntity($user);

		if (
			!$access->check(UserActionDictionary::DELETE, $targetUser)
			|| !$access->check(UserActionDictionary::FIRE, $targetUser)
		)
		{
			$this->addError(new Error('no permissions', 403));

			return false;
		}

		$command = new DeleteOrFireUserCommand($user);
		$result = $command->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction intranet.v2.User.fire
	 * @param \Bitrix\Intranet\Entity\User $user
	 * @return bool
	 * @throws AccessException
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function fireAction(\Bitrix\Intranet\Entity\User $user): bool
	{
		$access = UserAccessController::createByDefault();
		$targetUser = TargetUserModel::createFromUserEntity($user);

		if (
			!$access->check(UserActionDictionary::FIRE, $targetUser)
		)
		{
			$this->addError(new Error('no permissions', 403));

			return false;
		}

		$command = new FireUserCommand($user);
		$result = $command->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction intranet.v2.User.restore
	 * @param \Bitrix\Intranet\Entity\User $user
	 * @return bool
	 * @throws AccessException
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function restoreAction(\Bitrix\Intranet\Entity\User $user): bool
	{
		$access = UserAccessController::createByDefault();
		$targetUser = TargetUserModel::createFromUserEntity($user);

		if (
			!$access->check(UserActionDictionary::RESTORE, $targetUser)
		)
		{
			$this->addError(new Error('no permissions', 403));

			return false;
		}

		$command = new RestoreUserCommand($user);
		$result = $command->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction intranet.v2.User.delete
	 * @param \Bitrix\Intranet\Entity\User $user
	 * @return bool
	 * @throws AccessException
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 */
	public function deleteAction(\Bitrix\Intranet\Entity\User $user): bool
	{
		$access = UserAccessController::createByDefault();
		$targetUser = TargetUserModel::createFromUserEntity($user);

		if (
			!$access->check(UserActionDictionary::DELETE, $targetUser)
		)
		{
			$this->addError(new Error('no permissions', 403));

			return false;
		}

		$command = new DeleteUserCommand($user);
		$result = $command->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @throws AccessException
	 * @throws UnknownActionException
	 */
	public function fireWizardConfigAction(\Bitrix\Intranet\Entity\User $user): array
	{
		$access = UserAccessController::createByDefault();
		$targetUser = TargetUserModel::createFromUserEntity($user);

		if (
			!$access->check(UserActionDictionary::FIRE, $targetUser)
		)
		{
			$this->addError(new Error('no permissions', 403));

			return [];
		}

		return [
			'integration' => [
				'hasWebhook' => (new \Bitrix\Rest\Service\APAuth\PasswordService())->hasWebhooksByUserId($user->getId()),
			],
		];
	}

	/**
	 * @throws AccessException
	 * @throws CommandValidationException
	 * @throws UnknownActionException
	 * @throws CommandException
	 */
	public function moveWebhookAction(\Bitrix\Intranet\Entity\User $user, FireWizardConfig $fireWizardConfig): bool
	{
		$access = UserAccessController::createByDefault();
		$targetUser = TargetUserModel::createFromUserEntity($user);

		if (
			!$access->check(UserActionDictionary::FIRE, $targetUser)
		)
		{
			$this->addError(new Error('no permissions', 403));

			return false;
		}

		if ($fireWizardConfig->needMoveWebhook())
		{
			$result = (new ChangeOwnerCommand($user->getId()))->run();
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return false;
			}
		}

		return true;
	}

	/**
	 * @ajaxAction intranet.v2.User.removeIntegratorRights
	 */
	public function removeIntegratorRightsAction(\Bitrix\Intranet\Entity\User $user, DepartmentCollection $departmentCollection): bool
	{
		if ($departmentCollection->empty())
		{
			$this->addError(new Error('department not found'));

			return false;
		}

		if (!$user->isIntegrator())
		{
			$this->addError(new Error('user is not integrator', 403));

			return false;
		}

		if ($user->getActive() !== true)
		{
			$this->addError(new Error('user is not active', 403));

			return false;
		}

		$userId = (int)$user->getId();

		if ($userId <= 0)
		{
			return false;
		}

		try
		{
			(new DepartmentAssigner($departmentCollection))->reassignUser($user);
		}
		catch (\Throwable $exception)
		{
			$this->addError(new Error($exception->getMessage()));

			return false;
		}

		$removeResult = (new IntegratorGroup())->removeByUserId($userId);

		if (!$removeResult->isSuccess())
		{
			$this->addErrors($removeResult->getErrors());

			return false;
		}

		(new PartnerInfo())->removeByUserId($userId);
		\CIntranetEventHandlers::ClearAllUsersCache($userId);
		\Bitrix\Intranet\Composite\CacheProvider::deleteUserCache($userId);
		ServiceContainer::getInstance()->getUserService()->clearCache();

		return true;
	}
}
