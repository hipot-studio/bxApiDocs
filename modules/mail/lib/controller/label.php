<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Mail\Internal\Service\Label\LabelService;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

class Label extends Controller
{
	private const ERROR_MESSAGE_KEYS = [
		LabelService::ERROR_ACCESS_DENIED => 'MAIL_LABEL_ERROR_ACCESS_DENIED',
		LabelService::ERROR_NOT_FOUND => 'MAIL_LABEL_ERROR_LABEL_NOT_FOUND',
		LabelService::ERROR_MAILBOX_MISMATCH => 'MAIL_LABEL_ERROR_LABEL_MAILBOX_MISMATCH',
		LabelService::ERROR_NAME_EMPTY => 'MAIL_LABEL_ERROR_LABEL_NAME_EMPTY',
		LabelService::ERROR_NAME_NOT_UNIQUE => 'MAIL_LABEL_ERROR_LABEL_NAME_NOT_UNIQUE',
		LabelService::ERROR_LIMIT_EXCEEDED => 'MAIL_LABEL_ERROR_LABEL_LIMIT_EXCEEDED',
		LabelService::ERROR_BATCH_TOO_LARGE => 'MAIL_LABEL_ERROR_LABEL_BATCH_TOO_LARGE',
	];

	private ?LabelService $labelService = null;

	public function configureActions()
	{
		// Reading is open to POST as well: the only action transport of the mobile client sends POST,
		// so a GET-only read would be unreachable from it. The same shape as in Draft and
		// MessageConversation, the neighbouring controllers the mobile client already reads from.
		$read = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([
					ActionFilter\HttpMethod::METHOD_GET,
					ActionFilter\HttpMethod::METHOD_POST,
				]),
			],
		];

		return [
			'list' => $read,
			'messageLabels' => $read,
			'commonMessageLabels' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
			'add' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
			'update' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
			'delete' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
			'assign' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
			'unassign' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
		];
	}

	protected function processBeforeAction(Action $action)
	{
		if (!LabelsFeature::isEnabled())
		{
			$this->addError($this->localizeError(LabelService::ERROR_ACCESS_DENIED));

			return false;
		}

		return parent::processBeforeAction($action);
	}

	public function listAction(?int $mailboxId = null): ?array
	{
		return $this->respond($this->getLabelService()->getList($this->getCurrentUserId(), $mailboxId));
	}

	public function messageLabelsAction(string $id): array
	{
		return [
			'labelIds' => $this->getLabelService()->getMessageLabelIdsByCompositeId($this->getCurrentUserId(), $id),
		];
	}

	/**
	 * Labels attached to every message of the selection - the intersection, not the union.
	 *
	 * @param string[] $ids
	 */
	public function commonMessageLabelsAction(array $ids): array
	{
		return [
			'labelIds' => $this->getLabelService()->getCommonMessageLabelIds($this->getCurrentUserId(), $ids),
		];
	}

	public function addAction(string $name, int $mailboxId = 0): ?array
	{
		return $this->respond($this->getLabelService()->add($this->getCurrentUserId(), $name, $mailboxId));
	}

	public function updateAction(int $id, string $name): ?array
	{
		return $this->respond($this->getLabelService()->rename($this->getCurrentUserId(), $id, $name));
	}

	public function deleteAction(int $id): ?array
	{
		return $this->respond($this->getLabelService()->delete($this->getCurrentUserId(), $id));
	}

	public function assignAction(array $labelIds, array $ids): ?array
	{
		return $this->respond($this->getLabelService()->assign($this->getCurrentUserId(), $labelIds, $ids));
	}

	public function unassignAction(array $labelIds, array $ids): ?array
	{
		return $this->respond($this->getLabelService()->unassign($this->getCurrentUserId(), $labelIds, $ids));
	}

	protected function getLabelService(): LabelService
	{
		return $this->labelService ??= new LabelService();
	}

	private function respond(Result $result): ?array
	{
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addError($this->localizeError((string)$error->getCode()));
			}

			return null;
		}

		return $result->getData();
	}

	private function localizeError(string $code): Error
	{
		$key = self::ERROR_MESSAGE_KEYS[$code] ?? null;
		$message = $key === null ? null : Loc::getMessage($key);

		return new Error((string)($message ?? $code), $code);
	}

	private function getCurrentUserId(): int
	{
		return (int)($this->getCurrentUser()?->getId() ?? 0);
	}
}
