<?php

namespace Bitrix\Crm\Recurring\Mail;

use Bitrix\Crm\Integration\StorageManager;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\Invoice\Invoice;
use Bitrix\Crm\InvoiceRecurTable;
use Bitrix\Crm\Recurring\Entity\Item\InvoiceExist;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\BusinessValue;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem\Manager;
use CCrmActivity;
use CCrmInvoice;
use CCrmOwnerType;
use CSalePaySystemAction;

class InvoiceSender extends BaseSender
{
	protected function getMyCompanyId(array $itemData): ?int
	{
		return $itemData['UF_MYCOMPANY_ID'] ? (int)$itemData['UF_MYCOMPANY_ID'] : null;
	}

	// @todo refactore
	public function sendMail(): Result
	{
		$result = new Result();
		$now = new DateTime();

		$invoice = $this->itemData;

		if (empty($invoice['ID']) || empty($invoice['ACCOUNT_NUMBER']))
		{
			return (new Result())->addError(new Error('Mail sending error. Invoice data are empty!'));
		}

		$message = $this->fillEmailMessage($invoice);

		if (!check_email($this->dataFrom['VALUE']))
		{
			return (new Result())->addError(new Error('Mail sending error. Wrong owner email!'));
		}

		$attachmentId = $this->getPdfAttachment($invoice['ID']);
		if (is_bool($attachmentId))
		{
			if ($attachmentId === false)
			{
				$result->addError(new Error(Loc::getMessage('CRM_RECURRING_MAIL_SAVING_BILL')));
			}

			return $result;
		}

		$attachments = [$attachmentId];

		$this->serveInlineAttachments($attachments, $message);

		$fields = $this->getFields($message, $attachments);
		$fields['COMMUNICATIONS'] = [$this->dataTo[0]['COMMUNICATIONS']];
		$fields['BINDINGS'] = array_values($this->dataTo[0]['BINDINGS']);

		if (!($id = CCrmActivity::Add($fields, false, false, ['REGISTER_SONET_EVENT' => true])))
		{
			$result->addError(new Error(CCrmActivity::GetLastErrorMessage()));

			return $result;
		}

		CCrmActivity::SaveCommunications($id, $fields['COMMUNICATIONS'], $fields);

		$description = $fields['DESCRIPTION'];

		foreach ($attachments as $item)
		{
			$fileInfo = StorageManager::getFileInfo(
				$item,
				$fields['STORAGE_TYPE_ID'],
				false,
				[
					'OWNER_TYPE_ID' => CCrmOwnerType::Activity,
					'OWNER_ID' => $id,
				],
			);

			$description = str_replace(
				sprintf('bxacid:n%u', $item),
				htmlspecialcharsbx($fileInfo['VIEW_URL']),
				$description,
				$count,
			);

			if ($count > 0)
			{
				$descriptionUpdated = true;
			}

			$fileArray = StorageManager::makeFileArray($item, $fields['STORAGE_TYPE_ID']);

			$contentId = sprintf(
				'bxacid.%s@%s.crm',
				hash('crc32b', $fileArray['external_id'] . $fileArray['size'] . $fileArray['name']),
				hash('crc32b', $this->getHostUrl()),
			);
			$fields['DESCRIPTION'] = str_replace(
				sprintf('bxacid:n%u', $item),
				sprintf('cid:%s', $contentId),
				$fields['DESCRIPTION'],
			);
		}

		if (!empty($descriptionUpdated))
		{
			CCrmActivity::update(
				$id,
				['DESCRIPTION' => $description],
				false,
				false,
				['REGISTER_SONET_EVENT' => true],
			);
		}

		$sendEmailResult = $this->trySendEmail($id, $fields);
		if (!$sendEmailResult->isSuccess())
		{
			return $sendEmailResult;
		}

		$this->addEventToStatFile($invoice['RECURRING_ID'], $fields);

		if ($result->isSuccess())
		{
			$result->setData(['ACTIVITY_ID' => $id]);
		}

		return $result;
	}

	public function getPdfAttachment($invoiceId): int|bool
	{
		if (!Loader::includeModule('sale'))
		{
			return false;
		}

		$siteId = '';
		$invoice = Invoice::load($invoiceId);
		if (!$invoice)
		{
			return false;
		}

		$paymentCollection = $invoice->getPaymentCollection();
		/** @var Payment $payment */
		$payment = $paymentCollection->current();
		$paySystem = $payment->getPaymentSystemId();

		$action = new CSalePaySystemAction();

		$dbRes = Invoice::getList([
			'select' => ['*', 'UF_DEAL_ID', 'UF_QUOTE_ID', 'UF_COMPANY_ID', 'UF_CONTACT_ID', 'UF_MYCOMPANY_ID'],
			'filter' => ['ID' => $invoiceId],
		]);
		if ($data = $dbRes->fetch())
		{
			$paymentData = (
				is_array($data)
					? CCrmInvoice::PrepareSalePaymentData($data, ['PUBLIC_LINK_MODE' => 'Y'])
					: null
			);
			$action::InitParamArrays($data, $invoiceId, '', $paymentData, [], [], REGISTRY_TYPE_CRM_INVOICE);
			$siteId = $data['LID'];

			if (!empty($paymentData['USER_FIELDS']))
			{
				BusinessValue::redefineProviderField(['PROPERTY' => $paymentData['USER_FIELDS']]);
			}
		}

		$service = Manager::getObjectById($paySystem);
		if ($service && $service->isAffordPdf())
		{
			$file = $service->getPdf($payment);
			if ($file === null)
			{
				if ($service->isAffordDocumentGeneratePdf() && !$service->isPdfGenerated($payment))
				{
					$service->registerCallbackOnGenerate(
						$payment,
						[
							'CALLBACK_CLASS' => __CLASS__,
							'CALLBACK_METHOD' => 'sendAfterGeneratedPdf',
							'MODULE_ID' => 'crm',
						],
					);

					return true;
				}

				return false;
			}

			$storageTypeId = StorageType::getDefaultTypeID();

			$attachmentId = StorageManager::saveEmailAttachment($file, $storageTypeId, $siteId);

			return $attachmentId ?: false;
		}

		return false;
	}

	public static function sendAfterGeneratedPdf($invoiceId): void
	{
		$dbRes = Invoice::getList([
			'select' => ['RECURRING_ID'],
			'filter' => ['=ID' => $invoiceId],
		]);

		if ($data = $dbRes->fetch())
		{
			$dbRes = InvoiceRecurTable::getList([
				'select' => ['ID'],
				'filter' => ['=INVOICE_ID' => $data['RECURRING_ID']],
			]);

			if ($data = $dbRes->fetch())
			{
				$recurringInstance = InvoiceExist::load($data['ID']);
				if ($recurringInstance)
				{
					$preparedEmailData = $recurringInstance->getPreparedEmailData();
					if ($preparedEmailData)
					{
						$invoice = \Bitrix\Crm\Recurring\Entity\Invoice::getInstance();
						$emailData[$invoiceId] = [
							'EMAIL_ID' => (int)$preparedEmailData['EMAIL_ID'],
							'TEMPLATE_ID' => (int)$preparedEmailData['EMAIL_TEMPLATE_ID'] ?: null,
							'INVOICE_ID' => $invoiceId,
						];

						$invoice?->sendByMail([$preparedEmailData['EMAIL_ID']], $emailData);
					}
				}
			}
		}
	}

	protected function getEntityTypeId(): int
	{
		return CCrmOwnerType::Invoice;
	}

	protected function getResponsibleId(): int
	{
		return (int)($this->itemData['RESPONSIBLE_ID'] ?? 0);
	}
}
