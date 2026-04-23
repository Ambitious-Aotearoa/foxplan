<?php
namespace modules;

use yii\base\Event;
use craft\contactform\Mailer;
use craft\contactform\events\SendEvent;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\mail\Message;
use craft\helpers\App;
use Craft;

class Module extends \yii\base\Module
{
    public function init(): void
    {
        parent::init();
        $module = $this;

        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function (SendEvent $event) use ($module) {

                // 1. SILENCE THE DEFAULT PLUGIN EMAIL
                // This passes validation but prevents duplicate "ugly" emails.
                $event->toEmails = [];

                $submission = $event->submission;

                // 2. LOAD FROM .env
                $systemEmail = App::env('SYSTEM_EMAIL');
                $adminRecipient = App::env('ADMIN_RECIPIENT_EMAIL') ?: 'accounts@ambitious.co.nz';
                $fromName = App::env('EMAIL_SENDER_NAME') ?: "Foxplan";
                $replyTo = App::env('REPLY_TO_EMAIL');

                // Extract message data
                $messageData = $submission->message;
                $formType = $messageData['formType'] ?? null;
                $entryId = $messageData['entryId'] ?? null;

                // --- 3. LOGIC FOR ALL FORMS ---

                // PATH A: Standard Contact Form
                if ($formType === 'contact') {
                    $module->_sendStandardEmails($submission, $systemEmail, $fromName, $adminRecipient, $replyTo);
                }

                // PATH B: Landing Pages & First Home Buyers (Anything with an entryId)
                elseif ($entryId) {
                    $entry = Entry::find()->id((int)$entryId)->one();
                    if ($entry) {
                        // Sends Notification to Admin
                        $module->_sendAdminNotification($submission, $systemEmail, $fromName, $adminRecipient, $replyTo);

                        // Sends PDF/Confirmation to User
                        $emailEntry = $entry->emailMessageLink->one() ?? null;
                        $guideAsset = $entry->guidePdf->one() ?? Asset::find()->relatedTo($entry)->extension('pdf')->one();

                        if ($emailEntry) {
                            $module->_sendUserConfirmation($submission, $emailEntry, $guideAsset, $systemEmail, $fromName);
                        }
                    }
                }

                // CRITICAL: We NEVER set $event->handled = true;
                // This ensures "Contact Form Extensions" saves the data for EVERY form.
            }
        );
    }

    private function _sendAdminNotification($submission, $systemEmail, $fromName, $adminRecipient, $replyTo) {
        try {
            $message = new Message();
            $message->setFrom([$systemEmail => $fromName]);
            $message->setTo($adminRecipient);
            $message->setReplyTo($submission->fromEmail);
            $message->setSubject("New Lead: " . ($submission->message['formName'] ?? 'Website Inquiry'));
            $message->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/notification', [
                'submission' => $submission
            ]));
            Craft::$app->getMailer()->send($message);
        } catch (\Exception $e) {
            Craft::error("Admin Email Error: " . $e->getMessage(), __METHOD__);
        }
    }

    private function _sendUserConfirmation($submission, $emailEntry, $guideAsset, $systemEmail, $fromName) {
        try {
            $message = new Message();
            $message->setFrom([$systemEmail => $fromName]);
            $message->setTo($submission->fromEmail);
            $message->setSubject($emailEntry->emailHeadline ?? "Your FoxPlan Guide");
            $message->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/confirmation', [
                'submission' => $submission,
                'emailEntry' => $emailEntry
            ]));

            if ($guideAsset) {
                $filePath = $guideAsset->getCopyOfFile();
                if ($filePath && file_exists($filePath)) {
                    $message->attach($filePath, [
                        'fileName' => $guideAsset->filename,
                        'contentType' => 'application/pdf',
                    ]);
                    Craft::$app->getMailer()->send($message);
                    @unlink($filePath);
                    return;
                }
            }
            Craft::$app->getMailer()->send($message);
        } catch (\Exception $e) {
            Craft::error("User Email Error: " . $e->getMessage(), __METHOD__);
        }
    }

    private function _sendStandardEmails($submission, $systemEmail, $fromName, $adminRecipient, $replyTo) {
        $this->_sendAdminNotification($submission, $systemEmail, $fromName, $adminRecipient, $replyTo);
        try {
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
        } catch (\Exception $e) {
            Craft::error("Standard User Email Error: " . $e->getMessage(), __METHOD__);
        }
    }
}