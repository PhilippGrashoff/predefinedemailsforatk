<?php declare(strict_types=1);

namespace emailboilerplateforatkdata\tests\testclasses;

use emailboilerplateforatkdata\BaseEmail;

class EventInvitation extends BaseEmail
{
    public string $defaultTemplateFile = 'event_invitation.html';

    protected string $modelClassName = Event::class;

    protected string $emailTemplateHandlerClassName = ExtendedEmailTemplateHandler::class;

    protected function processMessageTemplateOnLoad(): void
    {
        if (!$this->entity) {
            return;
        }
        $this->messageTemplate->set('event_date', $this->entity->get('date')->format('Y-m-d'));
        if ($this->get('location_id')) {
            $this->messageTemplate->set('location_name', $this->entity->ref('location_id')->get('name'));
        }
    }
}
