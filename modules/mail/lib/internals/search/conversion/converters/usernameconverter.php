<?php

namespace Bitrix\Mail\Internals\Search\Conversion\Converters;

use Bitrix\Mail\Helper\Entity\User\User;
use Bitrix\Mail\Helper\Entity\User\UserProvider;
use Bitrix\Mail\Internals\Search\Conversion\ConverterInterface;

class UserNameConverter implements ConverterInterface
{
	private UserProvider $userProvider;

	public function __construct()
	{
		$this->userProvider = new UserProvider();
	}

	/**
	 * @param int $data
	 */
	public function convert($data): ?string
	{
		$userId = (string)$data;

		/** @var  User|null $userEntity */
		$userEntity = $this->userProvider->getEntityInfo($userId);

		return $userEntity?->getName();
	}
}
