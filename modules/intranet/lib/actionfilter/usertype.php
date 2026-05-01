<?php

namespace Bitrix\Intranet\ActionFilter;

use Bitrix\Intranet\UserTable;
use Bitrix\Main\Context;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\ModuleManager;

class UserType extends Engine\ActionFilter\Base
{
	/** @see \Bitrix\Main\UserTable::getExternalUserTypes */

	public const TYPE_CONTROLLER = '__controller';
	public const TYPE_BOT = 'bot';
	public const TYPE_CALL = 'call';
	public const TYPE_DOCUMENT_EDITOR = 'document_editor';
	public const TYPE_EMAIL = 'email';
	public const TYPE_EMPLOYEE = 'employee';
	public const TYPE_EXTRANET = 'extranet';
	public const TYPE_IMCONNECTOR = 'imconnector';
	public const TYPE_REPLICA = 'replica';
	public const TYPE_SALE = 'sale';
	public const TYPE_SALE_ANONYMOUS = 'saleanonymous';
	public const TYPE_SHOP = 'shop';

	public const ERROR_RESTRICTED_BY_USER_TYPE = 'restricted_by_user_type';

	/** @var array */
	private $allowedUserTypes;

	private ?\CUser $user;

	public function __construct(array $allowedUserTypes, ?\CUser $user = null)
	{
		$this->allowedUserTypes = $allowedUserTypes;
		$this->user = $user ?? $this->getCurrentUser();
		parent::__construct();
	}

	public function onBeforeAction(Event $event)
	{
		if (!$this->belongsCurrentUserToAllowedTypes())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error(
				'Access restricted by user type',
				self::ERROR_RESTRICTED_BY_USER_TYPE,
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	private function getCurrentUser(): ?\CUser
	{
		global $USER;

		return ($USER instanceof \CUser) ? $USER : null;
	}

	protected function belongsCurrentUserToAllowedTypes(): bool
	{
		if (!$this->hasAuthorizedCurrentUser())
		{
			return false;
		}

		if ($this->isAdminUser($this->user))
		{
			return true;
		}

		return $this->belongsUserToAllowedTypes($this->user);
	}

	protected function hasAuthorizedCurrentUser(): bool
	{
		return $this->user !== null && (int)$this->user->getId() > 0;
	}

	protected function isAdminUser(\CUser $user): bool
	{
		return $user->IsAdmin();
	}

	protected function belongsUserToAllowedTypes(\CUser $user): bool
	{
		$userType = $this->getExternalUserType($user);

		if ($userType !== null)
		{
			return $this->isAllowedUserType($userType);
		}

		return $this->belongsInternalUserToAllowedTypes((int)$user->getId());
	}

	protected function getExternalUserType(\CUser $user): ?string
	{
		return $this->resolveUserTypeByExternalAuthId((string)$user->GetParam('EXTERNAL_AUTH_ID'));
	}

	protected function belongsInternalUserToAllowedTypes(int $userId): bool
	{
		$possibleInternalUserTypes = $this->getPossibleInternalUserTypes();
		$allowedInternalUserTypes = $this->getAllowedInternalUserTypes($possibleInternalUserTypes);

		if (empty($allowedInternalUserTypes))
		{
			return false;
		}

		// There is no need to check the real UserType if the required UserType is employee or extranet|shop
		if ($this->allowsAllPossibleInternalUserTypes($possibleInternalUserTypes, $allowedInternalUserTypes))
		{
			return true;
		}

		$userType = $this->getUserTypeById($userId);

		return $userType !== null && $this->isAllowedUserType($userType);
	}

	protected function getAllowedInternalUserTypes(array $possibleInternalUserTypes): array
	{
		return array_intersect($possibleInternalUserTypes, $this->allowedUserTypes);
	}

	protected function allowsAllPossibleInternalUserTypes(
		array $possibleInternalUserTypes,
		array $allowedInternalUserTypes,
	): bool
	{
		return count($possibleInternalUserTypes) === count($allowedInternalUserTypes);
	}

	protected function isAllowedUserType(string $userType): bool
	{
		return in_array($userType, $this->allowedUserTypes, true);
	}

	protected function resolveUserTypeByExternalAuthId(string $externalAuthId): ?string
	{
		if ($externalAuthId === '')
		{
			return null;
		}

		$userTypeMap = $this->getExternalAuthIdToUserTypeMap();

		return $userTypeMap[$externalAuthId] ?? null;
	}

	protected function getExternalAuthIdToUserTypeMap(): array
	{
		$userTypeMap = $this->getModuleAwareExternalAuthIdToUserTypeMap();

		foreach (\Bitrix\Main\UserTable::getExternalUserTypes() as $externalAuthId)
		{
			if (!isset($userTypeMap[$externalAuthId]))
			{
				$userTypeMap[$externalAuthId] = $externalAuthId;
			}
		}

		return $userTypeMap;
	}

	protected function getModuleAwareExternalAuthIdToUserTypeMap(): array
	{
		$userTypeMap = [];

		if (ModuleManager::isModuleInstalled('sale'))
		{
			$userTypeMap[self::TYPE_SALE] = self::TYPE_SALE;
			$userTypeMap[self::TYPE_SALE_ANONYMOUS] = self::TYPE_SALE;
			$userTypeMap[self::TYPE_SHOP] = self::TYPE_SALE;
		}

		if (ModuleManager::isModuleInstalled('imconnector'))
		{
			$userTypeMap[self::TYPE_IMCONNECTOR] = self::TYPE_IMCONNECTOR;
		}

		if (ModuleManager::isModuleInstalled('im'))
		{
			$userTypeMap[self::TYPE_BOT] = self::TYPE_BOT;
		}

		if (ModuleManager::isModuleInstalled('mail'))
		{
			$userTypeMap[self::TYPE_EMAIL] = self::TYPE_EMAIL;
		}

		return $userTypeMap;
	}

	protected function getPossibleInternalUserTypes(): array
	{
		return [
			self::TYPE_EMPLOYEE,
			ModuleManager::isModuleInstalled('extranet')
				? self::TYPE_EXTRANET
				: self::TYPE_SHOP,
		];
	}

	protected function getUserTypeById(int $userId): ?string
	{
		$userData = UserTable::getRow([
			'filter' => [
				'=ID' => $userId,
			],
			'select' => ['ID', 'USER_TYPE'],
		]);

		return !empty($userData['USER_TYPE']) ? $userData['USER_TYPE'] : null;
	}
}
