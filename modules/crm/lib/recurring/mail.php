<?php

namespace Bitrix\Crm\Recurring;

use Bitrix\Crm\Recurring\Mail\InvoiceSender;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * @deprecated
 * @see \Bitrix\Crm\Recurring\Mail\BaseSender
 */
class Mail
{
	private ?InvoiceSender $sender = null;

	public function __construct()
	{
		$this->sender = InvoiceSender::getInstance();
	}

	public function setData($invoiceData = [], $sendData = [], $mailTemplateId = null)
	{
		return $this->sender->setData($invoiceData, $sendData, $mailTemplateId);
	}

	public function sendInvoice()
	{
		return $this->sender->sendMail();
	}

	public static function getPdfAttachment($invoiceId)
	{
		return InvoiceSender::getInstance()->getPdfAttachment($invoiceId);
	}

	public static function send($invoiceId)
	{
		InvoiceSender::sendAfterGeneratedPdf($invoiceId);
	}
}
