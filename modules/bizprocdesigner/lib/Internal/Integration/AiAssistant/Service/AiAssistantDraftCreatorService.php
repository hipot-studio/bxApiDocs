<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Api\Request\WorkflowTemplateLockService\GetLockRequest;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateLockService;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplateMetadata;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\BizprocDesignerPullManager;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\BizprocDesignerPullEvent;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\BizprocDesigner\Internal\Service\TemplateEditAccess;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

final readonly class AiAssistantDraftCreatorService
{
	private BizprocDesignerPullManager $pullManager;

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function __construct(?BizprocDesignerPullManager $pullManager = null)
	{
		$this->pullManager = $pullManager ?? Container::getPullManager();
	}

	/**
	 * @param int|null $draftRevision revision the write of the graph left behind; a caller that wrote nothing
	 * passes none and the revision is read from the draft
	 * @param AgentTemplateMetadata $templateMetadata metadata this very write stored, and only it (AGT-EVENT-01):
	 * a caller that wrote none passes none, and the tab then leaves the values it shows alone
	 */
	public function pushDraft(
		Draft $draft,
		?int $draftRevision = null,
		AgentTemplateMetadata $templateMetadata = new AgentTemplateMetadata(),
	): bool
	{
		if (!$draft->userId)
		{
			return false;
		}

		$recipientIds = $this->resolveRecipientIds($draft);
		if ($recipientIds === [])
		{
			return false;
		}

		return $this->pullManager->sendEventToUsers(
			$recipientIds,
			BizprocDesignerPullEvent::AiDraftUpdated,
			$this->toFrontendPayload($draft, $draftRevision, $templateMetadata),
		);
	}

	/**
	 * Who sees the draft of the agent: the user it works for and, when that is somebody else, the one holding
	 * the editing lease of the template — the draft lands in the open editor of both. The right to edit is
	 * checked on every recipient: a lease lives ten minutes and outlives a withdrawal of the rights, while the
	 * payload carries the whole graph of the draft.
	 *
	 * @return array<int>
	 */
	private function resolveRecipientIds(Draft $draft): array
	{
		if ($draft->templateId <= 0)
		{
			// A draft bound to no template has neither a lease nor a record to authorize against: the access
			// of the user of the agent to the document type is checked by the caller.
			return [$draft->userId];
		}

		$editAccess = new TemplateEditAccess();
		$tpl = $editAccess->resolve($draft->templateId);
		if ($tpl === null)
		{
			return [];
		}

		$recipientIds = [$draft->userId];
		$leaseOwnerId = self::getLeaseOwnerId($draft->templateId, $draft->userId);
		if ($leaseOwnerId !== null && $leaseOwnerId !== $draft->userId)
		{
			$recipientIds[] = $leaseOwnerId;
		}

		return array_values(array_filter(
			$recipientIds,
			static fn(int $userId): bool => $editAccess->canEdit($tpl, $userId),
		));
	}

	private static function getLeaseOwnerId(int $templateId, int $userId): ?int
	{
		if (!Feature::instance()->isTemplateEditLockAvailable())
		{
			return null;
		}

		return (new WorkflowTemplateLockService())
			->getLock(new GetLockRequest($templateId, $userId))
			->getLock()
			?->userId
		;
	}

	/**
	 * Готовит payload черновика для pull в той же frontend-схеме, что отдаёт
	 * штатная загрузка редактора (Diagram.get). Отличается только форма портов:
	 * доменный PortCollection::toArray группирует порты в объект { input, output },
	 * а редактор везде ждёт плоский массив портов с полем type (как bizproc
	 * NodePorts::toArray). Нормализуем в единственной точке отправки pull, не
	 * затрагивая общий Block::toArray — его используют REST-каталог и round-trip.
	 *
	 * The revision comes from the write that produced the graph whenever there was one: a second read of the
	 * draft may already answer with the next revision, and the graph would then travel with a revision that
	 * describes a content the recipient never saw.
	 *
	 * The name and the description are optional and carry the same rule (AGT-EVENT-01): the sender that did not
	 * write them - the Marta tool - puts neither key in, and the tab tells an absent value from an empty one.
	 */
	private function toFrontendPayload(
		Draft $draft,
		?int $draftRevision = null,
		AgentTemplateMetadata $templateMetadata = new AgentTemplateMetadata(),
	): array
	{
		$payload = $draft->toArray();
		$payload['blocks'] = array_map(
			static function (array $block): array {
				$block['ports'] = self::flattenPorts((array)($block['ports'] ?? []));

				return $block;
			},
			(array)($payload['blocks'] ?? []),
		);
		$payload['draftRevision'] = Feature::instance()->isTemplateEditLockAvailable()
			? $draftRevision ?? WorkflowTemplateDraftTable::getRevisionIfLatest($draft->templateId, $draft->draftId)
			: 0
		;

		if ($templateMetadata->name !== null)
		{
			$payload['name'] = $templateMetadata->name;
		}
		if ($templateMetadata->description !== null)
		{
			$payload['description'] = $templateMetadata->description;
		}

		return $payload;
	}

	/**
	 * Разворачивает порты из формы { <type>: [port, ...] } в плоский массив
	 * [{ ...port, type }, ...]. Уже плоский список возвращается как есть.
	 *
	 * @param array $ports
	 * @return array<int, array>
	 */
	private static function flattenPorts(array $ports): array
	{
		if (array_is_list($ports))
		{
			return $ports;
		}

		$flat = [];
		foreach ($ports as $type => $portList)
		{
			if (!is_array($portList))
			{
				continue;
			}

			foreach ($portList as $port)
			{
				if (is_array($port))
				{
					$port['type'] ??= $type;
					$flat[] = $port;
				}
			}
		}

		return $flat;
	}
}
