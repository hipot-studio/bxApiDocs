<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Calendar;
use Bitrix\Intranet;
use Bitrix\Mail\Integration\AI\SubjectGenerator;
use Bitrix\Mail\Service\Compose\SignatureListProvider;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

/**
 * Actions the redesigned compose form needs once it is already on the screen.
 * Scope: api, available as mail.api.composeform.*
 *
 * Every action works in the context of the current user and takes no user identifier: there is no
 * way to reach somebody else's signatures, sharing link or AI quota through them.
 *
 * Contract:
 *   getSignatures          -> {bySender: object<string, item[]>, choices: object<string, string>}
 *   getCalendarSharingLink -> {isSharingFeatureEnabled: bool, sharingUrl?: string}
 *   generateSubject        -> {queueHash: string} | {subject: string}
 *
 * The actions of the shared main.mail.form component stay where they are: the old form keeps calling
 * them.
 */
class ComposeForm extends Base
{
	/**
	 * Signatures of the current user, in the very shape the form starts with (DTO-01, key
	 * 'signatures', minus the settings path the initial data carries). Called after the signature
	 * settings slider is closed.
	 *
	 * Both maps are handed over as objects: an empty PHP map would otherwise serialise to [] and the
	 * type of the field would depend on whether there is data; the template of the form casts them
	 * the same way.
	 *
	 * @return array{bySender: \stdClass, choices: \stdClass}
	 */
	public function getSignaturesAction(): array
	{
		$signatures = (new SignatureListProvider())->getSignatures((int)$this->getCurrentUser()->getId());

		return [
			'bySender' => (object)$signatures['bySender'],
			'choices' => (object)$signatures['choices'],
		];
	}

	/**
	 * Short link to the calendar slots of the current user.
	 *
	 * The CRM deal branch of the shared component is not reproduced: the mail compose form has no deal
	 * to share slots for.
	 *
	 * @return array{isSharingFeatureEnabled: bool, sharingUrl?: string}
	 */
	public function getCalendarSharingLinkAction(): array
	{
		if (!$this->isCalendarSharingAvailable())
		{
			return ['isSharingFeatureEnabled' => false];
		}

		$sharing = new Calendar\Sharing\Sharing((int)$this->getCurrentUser()->getId());
		$response = ['isSharingFeatureEnabled' => $sharing->isEnabled()];

		$sharingUrl = (string)$sharing->getActiveLinkShortUrl();
		if ($sharingUrl !== '')
		{
			$response['sharingUrl'] = $sharingUrl;
		}

		return $response;
	}

	/**
	 * Generates a short subject out of the body being composed (API-01).
	 *
	 * The action stays thin: availability (the intranet membership of the user included), composition
	 * mode, CRM permissions, AI limits and the diagnostics of a breakdown are all decided by the
	 * service, and its errors travel to the client by their own codes - none of them rewritten, none
	 * of them generalised, the limit family of the ai facade included. The answer carries exactly one
	 * of the two fields: a queue hash for an asynchronous engine, a ready subject for a synchronous
	 * one.
	 *
	 * @param string $body Body being composed, signature stripped (primary source of the subject).
	 * @param string $mode One of "new" / "reply" / "forward".
	 * @param int|null $originalMessageId Saved original message id for reply and forward.
	 * @param string $currentSubject Current subject to improve; empty for generation from scratch.
	 * @param string $ownerType CRM entity type (id or symbolic name); empty for regular mail.
	 * @param int $ownerId CRM entity id; 0 for regular mail.
	 * @return array{queueHash?: string, subject?: string}|null
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function generateSubjectAction(
		string $body,
		string $mode = SubjectGenerator::MODE_NEW,
		?int $originalMessageId = null,
		string $currentSubject = '',
		string $ownerType = '',
		int $ownerId = 0,
	): ?array
	{
		try
		{
			$result = $this->createSubjectGenerator()->generate(
				$body,
				$mode,
				$originalMessageId,
				$currentSubject,
				$ownerType,
				$ownerId,
			);
		}
		catch (\Throwable $e)
		{
			$this->writeToLogException($e);
			$this->addError(new Error('AI subject generation failed', SubjectGenerator::ERROR_GENERATION_FAILED));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * The service is stateless and built per call; this factory is the seam the tests replace.
	 */
	protected function createSubjectGenerator(): SubjectGenerator
	{
		return new SubjectGenerator();
	}

	/**
	 * A missing calendar module and a user outside the intranet both mean the feature is off, not that
	 * the request has failed.
	 */
	protected function isCalendarSharingAvailable(): bool
	{
		if (!Loader::includeModule('calendar'))
		{
			return false;
		}

		return $this->isIntranetUser();
	}

	/**
	 * A portal without the intranet module has no extranet to tell anybody apart from, so everybody
	 * there counts as an intranet user.
	 */
	protected function isIntranetUser(): bool
	{
		return !Loader::includeModule('intranet') || Intranet\Util::isIntranetUser();
	}
}
