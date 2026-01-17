<?php

namespace Bitrix\MailMobile\Internal\Integration\Mail;

use Bitrix\Mail\Internals\UserSignatureTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class SignatureProvider
{
	/**
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function getSignature(string $email, string $name, ?int $userId = null): string
	{
		if (!$userId)
		{
			$userId = (int)CurrentUser::get()->getId();
		}

		$signatureList = UserSignatureTable::getList([
			'select' => ['SIGNATURE'],
			'order' => ['ID' => 'desc'],
			'filter' => [
				'=SENDER' => (trim($name) . ' <' . trim($email) . '>'),
				'=USER_ID' => $userId,
			],
			'limit' => 1,
		])->fetchAll();

		if (isset($signatureList[0]['SIGNATURE']))
		{
			return $signatureList[0]['SIGNATURE'];
		}

		$signatureList = UserSignatureTable::getList([
			'select' => ['SIGNATURE'],
			'order' => ['ID' => 'desc'],
			'filter' => [
				'=SENDER' => trim($email),
				'=USER_ID' => $userId,
			],
			'limit' => 1,
		])->fetchAll();

		return $signatureList[0]['SIGNATURE'] ?? $this->getGeneralSignature($userId);
	}

	/**
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function getGeneralSignature(?int $userId = null): string
	{
		if (!$userId)
		{
			$userId = (int)CurrentUser::get()->getId();
		}

		$signatureList = UserSignatureTable::getList([
			'select' => ['SIGNATURE'],
			'order' => ['ID' => 'desc'],
			'filter' => [
				'=SENDER' => '',
				'USER_ID' => $userId,
			],
			'limit' => 1,
		])->fetchAll();

		return $signatureList[0]['SIGNATURE'] ?? '';
	}
}
