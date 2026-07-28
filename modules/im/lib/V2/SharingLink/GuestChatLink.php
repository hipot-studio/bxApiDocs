<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\Integration\Network\GuestNetworkPortalRegistry;
use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

/**
 * Guest chat sharing link implementation.
 *
 * Uses individual links for each guest user.
 * External URL format: https://b24.to/gi/{portalId}-{code}
 * Fallback (no portal id / network unavailable): {publicDomain}/guest/{code}
 * Both formats may carry optional query string: ?IM_DIALOG=...&name=...
 */
class GuestChatLink extends ChatLink
{
	private const DEEPLINK_BASE_URL = 'https://b24.to/gi/';
	private const DEFAULT_LIFETIME = '1 DAY';

	public const SHARED_LINK_MAX_USES = 40;
	public const PERSONAL_LINK_MAX_USES = 1;

	private ?string $inviteUrl = null;

	public static function getEntityType(): LinkEntityType
	{
		return LinkEntityType::GuestChat;
	}

	public static function getDefaultDateExpire(): DateTime
	{
		return (new DateTime())->add(self::DEFAULT_LIFETIME);
	}

	public function canDo(Action $action, mixed $target = null): bool
	{
		$userId = Locator::getContext()->getUserId();

		if (!Permission::canDoActionByUserType($userId, $action, $target))
		{
			return false;
		}

		return parent::canDo($action, $target);
	}

	protected function getGuestNetworkPortalRegistry(): GuestNetworkPortalRegistry
	{
		return ServiceLocator::getInstance()->get(GuestNetworkPortalRegistry::class);
	}

	protected function getUrl(): string
	{
		return $this->buildUrl(null);
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

		$this->inviteUrl = $this->buildUrl($name);

		return $this->inviteUrl;
	}

	/**
	 * Pre-embeds IM_DIALOG so the target chat is addressable from the very first hit after redirect;
	 * the optional guest name is included as a separate query parameter.
	 */
	private function buildUrl(?string $name): string
	{
		$guestCode = $this->getCode();

		$portalId = $this->getGuestNetworkPortalRegistry()->getPortalId();
		if ($portalId !== null && $portalId !== '')
		{
			$url = self::DEEPLINK_BASE_URL . $portalId . '-' . $guestCode;
		}
		else
		{
			$url = \Bitrix\Im\Common::getPublicDomain() . '/guest/' . $guestCode;
		}

		$query = [];

		$dialogId = Chat::getInstance($this->getChatId())->getDialogId();
		if ($dialogId !== null && $dialogId !== '')
		{
			$query['IM_DIALOG'] = $dialogId;
		}

		$trimmedName = $name !== null ? trim($name) : '';
		if ($trimmedName !== '')
		{
			$query['name'] = $trimmedName;
		}

		if ($query !== [])
		{
			$url .= '?' . http_build_query($query);
		}

		return $url;
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
