<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Public\Command\Otp\Notification;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

class SendRequestRecoverAccessCommand extends AbstractCommand
{
	public function __construct(public User $user)
	{
	}

	/**
	 * @throws ArgumentTypeException
	 * @throws LoaderException
	 * @throws ArgumentOutOfRangeException
	 * @throws SystemException
	 */
	protected function execute(): Result
	{
		return (new SendRequestRecoverAccessHandler())($this);
	}
}
