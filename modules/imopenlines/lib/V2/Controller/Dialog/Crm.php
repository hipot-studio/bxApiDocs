<?php

namespace Bitrix\ImOpenLines\V2\Controller\Dialog;

use Bitrix\Im\V2\Chat;
use Bitrix\ImOpenLines\V2\Controller\BaseController;

class Crm extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Dialog.Crm.save
	 */
	public function saveAction(Chat $chat): ?array
	{
		$chatId = $chat->getChatId();
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chatId, (int)$currentUser?->getId());

		$result = $operator->createLead();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$olChat = new \Bitrix\ImOpenLines\Chat($chatId);
		$crmData = $olChat->getFieldData(\Bitrix\ImOpenLines\Chat::FIELD_CRM);

		$leadId = (int)($crmData['LEAD'] ?? 0) ?: null;
		$contactId = (int)($crmData['CONTACT'] ?? 0) ?: null;
		$dealId = (int)($crmData['DEAL'] ?? 0) ?: null;
		$companyId = (int)($crmData['COMPANY'] ?? 0) ?: null;

		return [
			'dialogCrm' => [
				'lead' => $leadId ? ['id' => $leadId] : null,
				'contact' => $contactId ? ['id' => $contactId] : null,
				'deal' => $dealId ? ['id' => $dealId] : null,
				'company' => $companyId ? ['id' => $companyId] : null,
			],
		];
	}
}
