<?php
namespace modules;

use Craft;
use craft\contactform\Mailer;
use craft\contactform\events\SendEvent;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\mail\Message;
use craft\helpers\App;
use yii\base\Event;

class Module extends \yii\base\Module
{
    public function init(): void
    {
        parent::init();

        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function (SendEvent $event) {
                $event->toEmails = [];

                $submission = $event->submission;
                $messageData = $submission->message;
                $formType = $messageData['formType'] ?? 'dynamic';
                $entryId = $messageData['entryId'] ?? null;

                $systemEmail = App::env('SYSTEM_EMAIL') ?: "noreply@mg.foxplan.nz";
                $fromName = App::env('EMAIL_SENDER_NAME') ?: "Foxplan";
                $adminRecipient = App::env('ADMIN_RECIPIENT_EMAIL') ?: "accounts@ambitious.co.nz";

                if ($formType === 'contact') {
                    $this->_sendStandardEmails($submission, $systemEmail, $fromName, $adminRecipient);
                } elseif ($entryId) {
                    $entry = Entry::find()->id((int)$entryId)->one();
                    if ($entry) {
                        $this->_sendLandingPageEmails($submission, $entry, $systemEmail, $fromName, $adminRecipient);
                    }
                }
            }
        );
    }

    private function _sendLandingPageEmails($submission, $entry, $systemEmail, $fromName, $adminRecipient) {
        try {
            $emailEntry = $entry->emailMessageLink->one() ?? null;

            // Logic: Try to find a PDF explicitly related to this entry
            $guideAsset = Asset::find()->relatedTo($entry)->kind('pdf')->one();

            // USER CONFIRMATION (With PDF Attachment)
            if ($emailEntry) {
                $userMsg = new Message();
                $userMsg->setFrom([$systemEmail => $fromName]);
                $userMsg->setTo($submission->fromEmail);
                $userMsg->setSubject($emailEntry->emailHeadline ?? "Your FoxPlan Guide");
                $userMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/confirmation', [
                    'submission' => $submission,
                    'emailEntry' => $emailEntry
                ]));

                // Attach PDF only if it exists
                if ($guideAsset) {
                    $path = $guideAsset->getCopyOfFile();
                    if ($path && file_exists($path)) {
                        $userMsg->attach($path, ['fileName' => $guideAsset->filename]);
                    }
                }

                Craft::$app->getMailer()->send($userMsg);
            }

            // ADMIN NOTIFICATION
            $adminMsg = new Message();
            $adminMsg->setFrom([$systemEmail => $fromName]);
            $adminMsg->setTo($adminRecipient);
            $adminMsg->setReplyTo($submission->fromEmail);

            // FIX: Prevent "Guide Guide" by checking if it already exists in the title
            $formName = $submission->message['formName'] ?? $entry->title;
            $cleanName = str_ireplace(' Guide', '', $formName); // Strip it if it's there

            $adminMsg->setSubject("New Lead: " . $cleanName . " Guide");

            $adminMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/notification', [
                'submission' => $submission
            ]));

            Craft::$app->getMailer()->send($adminMsg);

        } catch (\Exception $e) {
            Craft::error("Landing Page Email Error: " . $e->getMessage(), __METHOD__);
        }
    }

    private function _sendStandardEmails($submission, $systemEmail, $fromName, $adminRecipient) {
        try {
            // USER CONFIRMATION
            $userMsg = new Message();
            $userMsg->setFrom([$systemEmail => $fromName]);
            $userMsg->setTo($submission->fromEmail);
            $userMsg->setSubject("Thanks for reaching out to FoxPlan");
            $userMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/confirmation', [
                'submission' => $submission,
                'hardcodedText' => [
                    'headline' => 'Thanks for reaching out.',
                    'body' => "We've received your message and an adviser will be in touch shortly."
                ]
            ]));
            Craft::$app->getMailer()->send($userMsg);

            // ADMIN NOTIFICATION
            $adminMsg = new Message();
            $adminMsg->setFrom([$systemEmail => $fromName]);
            $adminMsg->setTo($adminRecipient);
            $adminMsg->setReplyTo($submission->fromEmail);
            $adminMsg->setSubject("New Website Contact: General Enquiry");

            $adminMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/notification', [
                'submission' => $submission
            ]));

            Craft::$app->getMailer()->send($adminMsg);

        } catch (\Exception $e) {
            Craft::error("Standard Email Error: " . $e->getMessage(), __METHOD__);
        }
    }
}