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
                $formName = $submission->message['formName'] ?? '';

                // 1. Only run for "Guide" forms
                if (!str_contains($formName, 'Guide')) {
                    return;
                }

                // 2. GET THE ENTRY DYNAMICALLY
                // Since the model failed us, we grab the entryId from the POST request directly
                $entryId = Craft::$app->request->getBodyParam('entryId');

                if (!$entryId) {
                    Craft::error('Confirmation Error: No entryId found in request.', __METHOD__);
                    return;
                }

                $entry = Entry::find()->id($entryId)->one();

                if (!$entry) {
                    Craft::error('Confirmation Error: Could not find entry with ID ' . $entryId, __METHOD__);
                    return;
                }

                // 3. Get the PDF from the 'guidePdf' field (handle from your screenshot)
                $guideAsset = $entry->guidePdf->one(); //

                if (!$guideAsset) {
                    Craft::info('No PDF found for entry: ' . $entry->title, __METHOD__);
                    return;
                }

                $pdfPath = $guideAsset->getCopyOfFile();
                if (!$pdfPath || !file_exists($pdfPath)) {
                    return;
                }

                // 4. Set Subject
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

                Craft::info("Success: {$formName} sent to {$submission->fromEmail}", __METHOD__);
            }
        );
    }
}