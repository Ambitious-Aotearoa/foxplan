<?php
namespace modules;

use yii\base\Event;
use craft\contactform\Mailer;
use craft\contactform\events\SendEvent;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\mail\Message;
use Craft;

class Module extends \yii\base\Module
{
    public function init(): void
    {
        parent::init();

        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function (SendEvent $event) {
                if ($event->isSpam) return;

                $submission = $event->submission;
                $formType = $submission->message['formType'] ?? 'dynamic';
                $entryId = $submission->message['entryId'] ?? null;

                $systemEmail = "accounts@ambitious.co.nz";
                $fromName = "Foxplan";
                $adminRecipient = "accounts@ambitious.co.nz";

                // PATH A: Standard Contact
                if ($formType === 'contact') {
                    $this->_sendStandardEmails($submission, $systemEmail, $fromName, $adminRecipient);
                    $event->isSpam = true;
                    return;
                }

                // PATH B: Landing Pages
                if ($entryId) {
                    $entry = Entry::find()->id($entryId)->one();
                    if ($entry) {
                        $emailEntry = $entry->emailMessageLink->one() ?? null;
                        $guideAsset = Asset::find()->relatedTo($entry)->kind('pdf')->one();

                        if ($guideAsset && $emailEntry) {
                            $fileData = $this->_getFileData($guideAsset->getUrl());
                            if ($fileData) {
                                // User Confirmation
                                $userMsg = new Message();
                                $userMsg->setFrom([$systemEmail => $fromName]);
                                $userMsg->setTo($submission->fromEmail);
                                $userMsg->setSubject($emailEntry->emailHeadline ?? "Your FoxPlan Guide");
                                $userMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/confirmation', [
                                    'submission' => $submission,
                                    'emailEntry' => $emailEntry
                                ]));
                                $userMsg->attachContent($fileData, [
                                    'fileName' => $guideAsset->filename,
                                    'contentType' => 'application/pdf',
                                    'disposition' => 'attachment'
                                ]);
                                Craft::$app->getMailer()->send($userMsg);

                                // Admin Notification
                                $adminMsg = new Message();
                                $adminMsg->setFrom([$systemEmail => $fromName]);
                                $adminMsg->setTo($adminRecipient);
                                $adminMsg->setSubject("New Guide Request: " . ($submission->message['formName'] ?? 'Landing Page'));
                                $adminMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/notification', [
                                    'submission' => $submission
                                ]));
                                Craft::$app->getMailer()->send($adminMsg);

                                $event->isSpam = true;
                            }
                        }
                    }
                }
            }
        );
    }

    private function _getFileData($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

    private function _sendStandardEmails($submission, $systemEmail, $fromName, $adminRecipient) {
        // User
        $userMsg = new Message();
        $userMsg->setFrom([$systemEmail => $fromName]);
        $userMsg->setTo($submission->fromEmail);
        $userMsg->setSubject("Thanks for reaching out to FoxPlan");
        $userMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/confirmation', [
            'submission' => $submission,
            'hardcodedText' => [
                'headline' => 'Thanks for reaching out.',
                'body' => "We've received your message and one of our friendly Foxplan advisers will be in touch with you shortly."
            ]
        ]));
        Craft::$app->getMailer()->send($userMsg);

        // Admin
        $adminMsg = new Message();
        $adminMsg->setFrom([$systemEmail => $fromName]);
        $adminMsg->setTo($adminRecipient);
        $adminMsg->setSubject("New Website Contact");
        $adminMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/notification', [
            'submission' => $submission
        ]));
        Craft::$app->getMailer()->send($adminMsg);
    }
}
