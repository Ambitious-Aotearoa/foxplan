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

                if (($submission->message['formName'] ?? '') !== 'First Home Buyers Guide') {
                    return;
                }

                $entry = Entry::find()->id(37)->one();
                if (!$entry) {
                    Craft::error('Could not find entry 37', __METHOD__);
                    return;
                }

                $guideAsset = $entry->guidePdf->one();
                if (!$guideAsset) {
                    Craft::error('Could not find guidePdf asset', __METHOD__);
                    return;
                }

                $pdfPath = $guideAsset->getCopyOfFile();
                if (!$pdfPath || !file_exists($pdfPath)) {
                    Craft::error('PDF file not found', __METHOD__);
                    return;
                }

                // Render confirmation template
                Craft::$app->view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_SITE);
                $html = Craft::$app->view->renderTemplate(
                    '_emails/confirmation',
                    ['submission' => $submission]
                );

                // Send fresh email to customer with PDF attached
                $mailer = Craft::$app->getMailer();
                $message = new Message();
                $message->setTo($submission->fromEmail);
                $message->setSubject('Your First Home Buyers Guide');
                $message->setHtmlBody($html);
                $message->attach($pdfPath, ['fileName' => $guideAsset->filename]);

                $mailer->send($message);

                Craft::info('PDF confirmation sent to: ' . $submission->fromEmail, __METHOD__);
            }
        );
    }
}