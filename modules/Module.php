<?php
namespace modules;

use yii\base\Event;
use craft\contactform\Mailer;
use craft\contactform\events\SendEvent;
use craft\elements\Entry;
use craft\mail\Message;
use Craft;

class Module extends \yii\base\Module
{
    public $controllerNamespace = '';

    public function init(): void
    {
        parent::init();

        Event::on(
            Mailer::class,
            Mailer::EVENT_AFTER_SEND,
            function (SendEvent $event) {
                $submission = $event->submission;

                // 1. Get the form name from the hidden input we added to the Twig form
                $formName = $submission->message['formName'] ?? '';

                // 2. Only run this for forms that are "Guides"
                if (!str_contains($formName, 'Guide')) {
                    return;
                }

                // 3. DYNAMICALLY find the entry based on where the form was submitted
                $entry = Entry::find()->id($submission->entryId)->one();

                if (!$entry) {
                    Craft::error('Confirmation Email Error: Could not find entry with ID ' . $submission->entryId, __METHOD__);
                    return;
                }

                // 4. Get the PDF from the 'guidePdf' field on THAT specific entry
                $guideAsset = $entry->guidePdf->one();
                if (!$guideAsset) {
                    Craft::info('No PDF found for entry: ' . $entry->title, __METHOD__);
                    return;
                }

                $pdfPath = $guideAsset->getCopyOfFile();
                if (!$pdfPath || !file_exists($pdfPath)) {
                    return;
                }

                // 5. Setup the Subject line dynamically
                // If it's KiwiSaver, use that title, otherwise use a default
                $subject = ($formName === 'KiwiSaver Guide')
                    ? 'Here’s your KiwiSaver Guide'
                    : 'Your ' . $formName;

                // Render confirmation template
                Craft::$app->view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_SITE);
                $html = Craft::$app->view->renderTemplate(
                    '_emails/confirmation',
                    ['submission' => $submission]
                );

                // Send the email
                $mailer = Craft::$app->getMailer();
                $message = new Message();
                $message->setTo($submission->fromEmail);
                $message->setSubject($subject);
                $message->setHtmlBody($html);
                $message->attach($pdfPath, ['fileName' => $guideAsset->filename]);

                $mailer->send($message);

                Craft::info("Dynamic confirmation sent: {$formName} to {$submission->fromEmail}", __METHOD__);
            }
        );
    }
}