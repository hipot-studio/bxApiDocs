<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\Infrastructure\Controller\AutoWire\UserParameterTrait;
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
use Bitrix\Main\ModuleManager;
use Bitrix\Rest\Public\Command\IncomingWebhook\ChangeOwnerCommand;

class User extends \Bitrix\Main\Engine\Controller
{
	use UserParameterTrait;

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
}
