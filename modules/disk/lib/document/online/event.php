<?php

namespace Bitrix\Disk\Document\Online;

use Bitrix\Disk\Driver;
use Bitrix\Main\Loader;
use Bitrix\Pull;

class event
{
    /** @var string */
    private $category;

    /** @var array */
    private $data;

    public function __construct(string $category, array $data = [])
    {
        $this->category = $category;
        $this->data = $data;
    }

    final public function send(array $recipients): void
    {
        if (!Loader::includeModule('pull')) {
            return;
        }

        $recipients = array_filter(array_unique($recipients));
        $recipients = $this->resolveRecipients($recipients);
        if (empty($recipients)) {
            return;
        }

        $message = [
            'module_id' => Driver::INTERNAL_MODULE_ID,
            'command' => $this->category,
            'params' => $this->data,
        ];

        Pull\Event::add($recipients, $message);
    }

    protected function resolveRecipients(array $recipients): array
    {
        return $recipients;
    }
}
