<?php declare(strict_types=1);

namespace emailboilerplateforatkdata\tests\emailimplementations;

use Atk4\Ui\HtmlTemplate;
use emailboilerplateforatkdata\EmailTemplate;
use emailboilerplateforatkdata\tests\testclasses\Location;

class LocationEmailTemplateHandler extends DefaultEmailTemplateHandler
{
    //we check if the a custom template for the location of an event is in database
    protected function customLoadTemplateForEntity(): ?HtmlTemplate
    {
        if (!$this->baseEmail->entity->get('location_id')) {
            return null;
        }
        $emailTemplate = new EmailTemplate($this->baseEmail->persistence);
        $emailTemplate->addCondition('model_class', '=', Location::class);
        $emailTemplate->addCondition('model_id', '=', $this->baseEmail->entity->get('location_id'));
        $emailTemplate->tryLoadBy('ident', (new \ReflectionClass($this->baseEmail))->getName());
        if (!$emailTemplate->loaded()) {
            return null;
        }
        $htmlTemplate = new $this->htmlTemplateClass($emailTemplate->get('value'));
        return $htmlTemplate;
    }

}