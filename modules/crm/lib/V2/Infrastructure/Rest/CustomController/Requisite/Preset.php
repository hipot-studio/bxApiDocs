<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\PresetDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\PresetDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Preset\GetPresetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Preset\ListPresetRequest;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Requisite\Exception\NotFoundException;
use Bitrix\Crm\V2\Public\Provider\Requisite\PresetProvider;
use Bitrix\Main\AccessDeniedException as OwnerTypeAccessDeniedException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * `crm.contact.requisite.preset.*` and `crm.company.requisite.preset.*` - the two reading methods over the
 * presets a portal offers, on one implementation.
 *
 * A preset is where a client learns what it may name in `presetId` of a requisite and what naming one will
 * get it, which is why the group publishes it at all. Reading only: managing the presets of a portal is
 * not part of this group and {@see PresetDto} carries no writable field.
 *
 * The type of the owner is an invariant of the route, as it is for the requisites themselves
 * ({@see AbstractController::getEntityType()}), and both reads need it: the composition of presets is the
 * same for a contact and for a company, and what differs is which of them is the default one.
 *
 * Two refusals and nothing else. A user who may not read the entities of the owner type is answered by
 * access - it is a refusal of the action rather than an answer about an object, and it says nothing about
 * which presets a portal keeps. A preset outside the composition the portal offers - absent, or switched
 * off - is answered as absence: a preset a client was never offered is one it cannot choose, and an answer
 * describing it would offer it.
 */
#[DtoType(PresetDto::class)]
final class Preset extends AbstractController
{
	public function getAction(GetPresetRequest $request, EntityType $entityType): GetResponse
	{
		try
		{
			$preset = (new PresetProvider($this->userId))->getById($entityType->getId(), $request->id);
		}
		catch (NotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}
		catch (OwnerTypeAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		return new GetResponse((new PresetDtoMapper())->getDtoByPreset($preset));
	}

	/**
	 * The composition entire, in the order the client asked for or in the one the interface shows.
	 *
	 * The ordering is carried to the read rather than applied to the answer, and it needs no translating:
	 * the two sortable fields of the contract are the two names the read takes, so there is no vocabulary
	 * to cross and no mapper of the request in this group.
	 */
	public function listAction(ListPresetRequest $request, EntityType $entityType): ListResponse
	{
		try
		{
			$presets = (new PresetProvider($this->userId))->getList(
				$entityType->getId(),
				$request->order?->getList() ?? [],
			);
		}
		catch (OwnerTypeAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		return new ListResponse((new PresetDtoMapper())->getDtoCollectionByPresets($presets));
	}
}
