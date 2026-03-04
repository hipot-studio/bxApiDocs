<?php

namespace Bitrix\Voximplant\Agent;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Voximplant\AccountVerification;
use Bitrix\Voximplant\Integration\Im;

Loc::loadMessages(__FILE__);

/**
 * Agent for checking INTERNOD verification status
 */
class VerificationChecker
{
	/**
	 * Checks for INTERNOD warning and sends notifications to administrators
	 *
	 * @return string Agent method name to reschedule daily, or empty string to terminate
	 */
	public static function checkInternodWarning(): string
	{
		$verification = new AccountVerification();

		// Only process VERIFICATIONS type, skip DOCUMENTS or null
		$source = $verification->getVerificationSource();
		if ($source !== 'VERIFICATIONS')
		{
			return __METHOD__ . '();';
		}

		if (!$verification->hasInternodWarning())
		{
			return '';
		}

		$documents = new \CVoxImplantDocuments();
		$billingUrl = $documents->GetUploadUrl('RU');
		$params = [
			'#BILLING_URL#' => $billingUrl,
		];
		$message = 'VOXIMPLANT_AGENT_INTERNOD_WARNING_MESSAGE';

		$deadline = $verification->getInternodDeadline();
		if ($deadline)
		{
			$params['#DATE#'] = $deadline;
			$message = 'VOXIMPLANT_AGENT_INTERNOD_WARNING_MESSAGE_WITH_DATE';
		}

		Im::notifyAdmins(
			Loc::getMessage($message, $params),
			[
				[
					'TEXT' => Loc::getMessage('VOXIMPLANT_AGENT_INTERNOD_BUTTON_SETTINGS'),
					'LINK' => $billingUrl
				]
			]
		);

		return __METHOD__ . '();';
	}
}
