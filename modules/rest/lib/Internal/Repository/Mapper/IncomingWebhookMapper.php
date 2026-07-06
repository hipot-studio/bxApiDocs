<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Rest\APAuth\EO_Password;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookAttributeCollection;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\WebhookType;

class IncomingWebhookMapper
{
	public function convertFromOrm(
		EO_Password $ormModel,
		array $scopes = [],
		?IncomingWebhookAttributeCollection $externalAttributes = null,
	): IncomingWebhook
	{
		$webhook = new IncomingWebhook(
			userId: (int)$ormModel->getUserId(),
			password: $ormModel->getPassword(),
			active: $ormModel->getActive(),
			title: $ormModel->getTitle(),
			comment: $ormModel->getComment(),
			dateCreate: $ormModel->getDateCreate(),
			dateLogin: $ormModel->getDateLogin(),
			lastIp: $ormModel->getLastIp(),
			scopes: $scopes,
			externalAttributes: $externalAttributes,
			type: $this->toWebhookType((string)$ormModel->getType()),
		);

		$webhook->setId($ormModel->getId());

		return $webhook;
	}

	public function convertToOrm(IncomingWebhook $entity): EO_Password
	{
		$ormModel = $entity->getId()
			? EO_Password::wakeUp($entity->getId())
			: PasswordTable::createObject()
		;

		$ormModel
			->setUserId($entity->getUserId())
			->setPassword($entity->getPassword())
			->setActive($entity->isActive())
			->setType($this->toPasswordType($entity->getType())->value)
			->setTitle($entity->getTitle())
			->setComment($entity->getComment())
			->setDateCreate($entity->getDateCreate())
			->setDateLogin($entity->getDateLogin())
			->setLastIp($entity->getLastIp())
		;

		return $ormModel;
	}

	private function toWebhookType(string $passwordType): WebhookType
	{
		return $passwordType === PasswordType::System->value
			? WebhookType::System
			: WebhookType::User;
	}

	private function toPasswordType(WebhookType $type): PasswordType
	{
		return $type === WebhookType::System
			? PasswordType::System
			: PasswordType::User;
	}
}
