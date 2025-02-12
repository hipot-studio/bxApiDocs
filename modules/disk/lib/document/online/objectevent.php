<?php

namespace Bitrix\Disk\Document\Online;

use Bitrix\Disk\BaseObject;

final class objectevent extends Event
{
    /** @var BaseObject */
    private $baseObject;

    public function __construct(BaseObject $baseObject, string $category, array $data = [])
    {
        $this->baseObject = $baseObject;
        parent::__construct($category, $data);
    }

    public function sendToObjectChannel(): void
    {
        $objectChannel = new ObjectChannel($this->baseObject->getRealObjectId());

        $this->send([$objectChannel]);
    }

    protected function resolveRecipients(array $recipients): array
    {
        $resolved = [];
        foreach ($recipients as $recipient) {
            if ($recipient instanceof ObjectChannel) {
                $resolved[] = $recipient->getPullModel();
            } else {
                $resolved[] = $recipient;
            }
        }

        return $resolved;
    }
}
