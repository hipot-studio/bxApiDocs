<?php

namespace Bitrix\Crm\Security;

use Bitrix\Crm\Integration\Im\Chat;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

class PermissionToken
{
	public const ACCESS_TYPE_EDIT_MY_COMPANY_REQUISITE = 'edit_my_company_requisite';
	public const ACCESS_TYPE_VIEW_ACTIVITY = 'view_activity';

	private const SALT = 'permission_token.';

	public static function createEditMyCompanyRequisitesToken(int $ownerEntityTypeId, ?int $ownerEntityId): string
	{
		$token = new self();

		return $token->createToken(self::ACCESS_TYPE_EDIT_MY_COMPANY_REQUISITE, [
			'ownerEntityTypeId' => $ownerEntityTypeId,
			'ownerEntityId' => $ownerEntityId,
		]);
	}

	public static function canEditRequisites(string $token, int $entityTypeId, int $entityId): bool
	{
		$instance = new self();

		return $instance->isValid(self::ACCESS_TYPE_EDIT_MY_COMPANY_REQUISITE, $token, [
			'entityTypeId' => $entityTypeId,
			'entityId' => $entityId,
		]);
	}

	public static function createViewActivityToken(int $activityId, int $chatId): string
	{
		$instance = new self();

		return $instance->createToken(self::ACCESS_TYPE_VIEW_ACTIVITY, [
			'activityId' => $activityId,
			'chatId' => $chatId,
		]);
	}

	public static function canViewActivity(string $token, int $activityId, int $userId): bool
	{
		$instance = new self();

		return $instance->isValid(self::ACCESS_TYPE_VIEW_ACTIVITY, $token, [
			'activityId' => $activityId,
			'userId' => $userId,
		]);
	}

	private function createToken(string $accessTypeCode, array $payload): string
	{
		if (!$this->isValidAccessTypeCode($accessTypeCode))
		{
			throw new ArgumentOutOfRangeException('accessTypeCode');
		}

		$signer = new \Bitrix\Main\Security\Sign\Signer();

		return $signer->sign(base64_encode(Json::encode($payload)), $this->getSalt($accessTypeCode));
	}

	public function isValid(string $accessTypeCode, string $token, array $data): bool
	{
		if ($token === '')
		{
			return false;
		}

		$signer = new \Bitrix\Main\Security\Sign\Signer;
		try
		{
			$payload = (array)Json::decode(
				base64_decode(
					$signer->unsign($token, $this->getSalt($accessTypeCode))
				)
			);

			switch ($accessTypeCode)
			{
				case self::ACCESS_TYPE_EDIT_MY_COMPANY_REQUISITE:
					$ownerEntityTypeId = $payload['ownerEntityTypeId'] ?? null;
					$ownerEntityId = $payload['ownerEntityId'] ?? null;

					if (!\CCrmOwnerType::IsDefined($ownerEntityTypeId))
					{
						return false;
					}

					$userPermissions = Container::getInstance()->getUserPermissions();

					$isCompany = isset($data['entityTypeId'])
						&& isset($data['entityId'])
						&& (int)$data['entityTypeId'] === \CCrmOwnerType::Company
					;

					$isNewCompany = $isCompany && (int)$data['entityId'] === 0;
					$isMyCompanyEntity = $isCompany && !$isNewCompany && \CCrmCompany::isMyCompany((int)$data['entityId']);

					return
						($isNewCompany || $isMyCompanyEntity)
						&& $userPermissions->item()->canUpdate($ownerEntityTypeId, $ownerEntityId)
					;

				case self::ACCESS_TYPE_VIEW_ACTIVITY:
					$tokenActivityId = (int)($payload['activityId'] ?? 0);
					$tokenChatId = (int)($payload['chatId'] ?? 0);
					$requestedActivityId = (int)($data['activityId'] ?? 0);
					$userId = (int)($data['userId'] ?? 0);

					if ($tokenActivityId <= 0 || $tokenActivityId !== $requestedActivityId)
					{
						return false;
					}

					if ($tokenChatId <= 0 || $userId <= 0)
					{
						return false;
					}

					if (!Loader::includeModule('im'))
					{
						return false;
					}

					$relation = Chat::getUserRelationToChat($tokenChatId, $userId);

					return !is_null($relation);
			}

			return false;
		}
		catch (\Bitrix\Main\Security\Sign\BadSignatureException $e)
		{
			return false;
		}
	}

	private function isValidAccessTypeCode(string $accessTypeCode): bool
	{
		return in_array(
			$accessTypeCode,
			[
				self::ACCESS_TYPE_EDIT_MY_COMPANY_REQUISITE,
				self::ACCESS_TYPE_VIEW_ACTIVITY,
			]
		);
	}

	private function getSalt(string $accessTypeCode): string
	{
		return self::SALT . $accessTypeCode;
	}
}
