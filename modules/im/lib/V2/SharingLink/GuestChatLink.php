<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;

/**
 * Guest chat sharing link implementation.
 *
 * Uses individual links for each guest user.
 * External URL format: https://b24.to/gi/{base64url(protocol://portal/code)}
 * Internal URL format: /guest/{code}
 */
class GuestChatLink extends ChatLink
{
	private const DEEPLINK_BASE_URL = 'https://b24.to/gi/';

	public const SHARED_LINK_MAX_USES = 5;
	public const PERSONAL_LINK_MAX_USES = 1;

	private ?string $inviteUrl = null;

	public static function getEntityType(): LinkEntityType
	{
		return LinkEntityType::GuestChat;
	}

	public function canDo(Action $action, mixed $target = null): bool
	{
		if ($action === Action::UpdateGuestLink)
		{
			$userId = Locator::getContext()->getUserId();

			return in_array($userId, $this->getUsersWithAccess(), true);
		}

		return parent::canDo($action, $target);
	}

	protected function getUrl(): string
	{
		$internalUrl = \Bitrix\Im\Common::getPublicDomain() . '/guest/' . $this->getCode();

		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			return $internalUrl;
		}

		$encoded = rtrim(strtr(base64_encode($internalUrl), '+/', '-_'), '=');

		return self::DEEPLINK_BASE_URL . $encoded;
	}

	/**
	 * Build invitation URL with optional guest name parameter.
	 * Result is cached for consistency within the same request.
	 */
	public function getInviteUrl(?string $name = null): string
	{
		if ($this->inviteUrl !== null)
		{
			return $this->inviteUrl;
		}

		$url = $this->getUrl();

		if ($name !== null && trim($name) !== '')
		{
			$url .= '?' . http_build_query(['name' => trim($name)]);
		}

		$this->inviteUrl = $url;

		return $this->inviteUrl;
	}

	public function toRestFormat(array $option = []): ?array
	{
		$result = parent::toRestFormat($option);

		$result['name'] = $this->getName();

		if ($this->inviteUrl !== null)
		{
			$result['url'] = $this->inviteUrl;
		}

		return $result;
	}

	/**
	 * Atomically increment the usage counter if the limit has not been reached.
	 *
	 * @return Result Success if the counter was incremented, error if the limit was already reached.
	 */
	public function incrementUsesCount(): Result
	{
		$result = new Result();

		$tableName = SharingLinkTable::getTableName();
		$connection = Application::getConnection();
		$connection->queryExecute(
			"UPDATE {$tableName} SET USES_COUNT = USES_COUNT + 1 WHERE ID = {$this->getPrimaryId()} AND USES_COUNT < MAX_USES"
		);

		if ($connection->getAffectedRowsCount() === 0)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::USES_LIMIT_REACHED));
		}

		$row = $connection->query(
			"SELECT USES_COUNT FROM {$tableName} WHERE ID = {$this->getPrimaryId()}"
		)->fetch();
		$this->setUsesCount((int)($row['USES_COUNT'] ?? 0));

		return $result;
	}

	/**
	 * Get chat ID from entity ID.
	 */
	public function getChatId(): int
	{
		return (int)$this->getEntityId();
	}
}
