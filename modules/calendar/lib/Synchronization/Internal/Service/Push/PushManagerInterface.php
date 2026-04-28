<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Push;

use Bitrix\Calendar\Sync\Connection\Connection;
use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\PushException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\AuthorizationException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotAuthorizedException;

interface PushManagerInterface
{
	/**
	 * @throws AuthorizationException
	 * @throws NotAuthorizedException
	 * @throws PushException
	 */
	public function subscribeConnection(Connection $connection): void;

	/**
	 * @throws AuthorizationException
	 * @throws NotAuthorizedException
	 * @throws PushException
	 */
	public function subscribeSection(SectionConnection $sectionConnection): void;

	/**
	 * @throws AuthorizationException
	 * @throws NotAuthorizedException
	 * @throws PushException
	 */
	public function resubscribeConnectionFully(Connection $connection): void;
}
