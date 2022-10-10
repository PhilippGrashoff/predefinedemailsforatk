<?php declare(strict_types=1);

namespace emailboilerplateforatkdata\tests\emailimplementations;

use emailboilerplateforatkdata\BasePredefinedEmail;
use emailboilerplateforatkdata\SentEmail;
use emailboilerplateforatkdata\tests\testclasses\Event;

class EventInvitation extends BasePredefinedEmail
{
    public string $defaultTemplateFile = 'event_invitation.html';
    protected string $modelClass = Event::class;
    protected string $emailTemplateHandlerClass = LocationEmailTemplateHandler::class;

    protected function processMessageTemplateOnLoad(): void
    {
        if (!$this->entity) {
            return;
        }
        $this->messageTemplate->set('event_name', $this->entity->get('name'));
        $this->messageTemplate->set('event_date', $this->entity->get('date')->format('Y-m-d'));
        if ($this->entity->get('location_id')) {
            $this->messageTemplate->set('location_name', $this->entity->ref('location_id')->get('name'));
        }
    }
}
