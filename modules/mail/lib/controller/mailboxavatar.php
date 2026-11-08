<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarResourceService;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class MailboxAvatar extends Base
{
	private const VIEW_REFUSAL_STATUSES = [
		AvatarResourceService::ERROR_ACCESS_DENIED => 403,
		AvatarResourceService::ERROR_NOT_SUPPORTED => 404,
		AvatarService::ERROR_VERSION_CONFLICT => 404,
		AvatarImageProcessor::ERROR_IMAGE_INVALID => 404,
	];

	public function configureActions(): array
	{
		return [
			'get' => [
				'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
			],
			'view' => [
				'-prefilters' => [Csrf::class],
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_GET]),
					new CloseSession(),
				],
			],
		];
	}

	public function getAction(int $mailboxId): ?array
	{
		$result = (new AvatarService())->get($mailboxId);
		if (!$result->isSuccess())
		{
			$this->addLocalizedErrors($result);

			return null;
		}

		return $result->getData();
	}

	public function viewAction(int $mailboxId, int $revision): ?HttpResponse
	{
		$result = (new AvatarService())->getImage($mailboxId, $revision);
		if (!$result->isSuccess())
		{
			return $this->refuseImage($result);
		}

		$content = $result->getData()['content'];
		$response = new HttpResponse();
		$response->setContent($content);
		$response->getHeaders()->set('Content-Type', 'image/png');
		$response->getHeaders()->set('Content-Disposition', 'inline; filename="avatar.png"');
		$response->getHeaders()->set('Content-Length', (string)strlen($content));
		self::addProtectiveHeaders($response);

		return $response;
	}

	/**
	 * The engine turns the returned null into a JSON body of its own, so the refusal states its status and
	 * repeats the protective headers of the image: an <img> tag must not take it for a picture or keep it.
	 */
	private function refuseImage(Result $result): null
	{
		$code = $result->getError()?->getCode();
		$response = Context::getCurrent()->getResponse();
		$response->setStatus(is_string($code) ? (self::VIEW_REFUSAL_STATUSES[$code] ?? 500) : 500);
		self::addProtectiveHeaders($response);
		$this->addLocalizedErrors($result);

		return null;
	}

	private static function addProtectiveHeaders(HttpResponse $response): void
	{
		$response->getHeaders()->set('Cache-Control', 'private, no-store');
		$response->getHeaders()->set('X-Content-Type-Options', 'nosniff');
	}

	private function addLocalizedErrors(Result $result): void
	{
		Loc::loadMessages(__FILE__);
		foreach ($result->getErrors() as $error)
		{
			$code = $error->getCode();
			$this->addError(new Error(Loc::getMessage($code), $code));
		}
	}
}
