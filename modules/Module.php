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
    public function init(): void
    {
        parent::init();

        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function (SendEvent $event) {
                $submission = $event->submission;
                $formType = $submission->message['formType'] ?? 'dynamic';
                $entryId = $submission->message['entryId'] ?? null;

                // --- CONFIGURATION FROM YOUR SETTINGS ---
                $systemEmail = "accounts@ambitious.co.nz";
                $fromName = "Foxplan";
                $adminRecipient = "accounts@ambitious.co.nz";

                // --- PATH A: CONTACT FORM (Hardcoded) ---
                if ($formType === 'contact') {
                    // 1. User Confirmation
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

                    // 2. Admin Notification
                    $adminMsg = new Message();
                    $adminMsg->setFrom([$systemEmail => $fromName]);
                    $adminMsg->setTo($adminRecipient);
                    $adminMsg->setSubject("New Website Contact");
                    $adminMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/notification', [
                        'submission' => $submission
                    ]));
                    Craft::$app->getMailer()->send($adminMsg);

                    $event->isSpam = true;
                    return;
                }

                // --- PATH B: LANDING PAGES (Dynamic) ---
                if ($entryId) {
                    $entry = Entry::find()->id($entryId)->one();
                    if ($entry) {
                        $guideAsset = $entry->guidePdf->one() ?? null;
                        $emailEntry = $entry->emailMessageLink->one() ?? null;

                        if ($emailEntry && $guideAsset) {
                            $filePath = $guideAsset->getCopyOfFile();
                            if ($filePath && file_exists($filePath)) {
                                // 1. User Confirmation (with PDF)
                                $userMsg = new Message();
                                $userMsg->setFrom([$systemEmail => $fromName]);
                                $userMsg->setTo($submission->fromEmail);
                                $userMsg->setSubject($emailEntry->emailHeadline ?? "Your FoxPlan Guide");
                                $userMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/confirmation', [
                                    'submission' => $submission,
                                    'emailEntry' => $emailEntry
                                ]));
                                $userMsg->attach($filePath, ['fileName' => $guideAsset->filename, 'contentType' => 'application/pdf']);
                                Craft::$app->getMailer()->send($userMsg);

                                // 2. Admin Notification
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
}