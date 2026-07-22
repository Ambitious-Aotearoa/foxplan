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
    /**
     * Hidden honeypot field rendered on the public forms. Bots that blindly fill
     * every field populate it; real users never see it, so a non-empty value = spam.
     */
    private const HONEYPOT_FIELD = 'website';

    /**
     * Max submissions allowed per client IP within RATE_WINDOW seconds. This handler
     * only runs for submissions that already passed reCAPTCHA, so a genuine visitor
     * will never come close to this.
     */
    private const RATE_LIMIT = 6;
    private const RATE_WINDOW = 3600;

    public function init(): void
    {
        parent::init();

        // Attach AFTER the app has fully initialised so this handler runs *after*
        // Contact Form Extensions' reCAPTCHA handler. That ordering guarantees any
        // upstream spam verdict ($event->isSpam) is already set before we send, and
        // if reCAPTCHA marks the submission spam it sets $event->handled = true, so
        // this handler never runs for those. Attaching directly in init() is NOT
        // safe: the module could run before the plugin and send before the spam
        // check happened (the original bug that let the spam through).
        Craft::$app->onInit(function () {
            Event::on(
                Mailer::class,
                Mailer::EVENT_BEFORE_SEND,
                [$this, 'handleBeforeSend']
            );
        });
    }

    /**
     * Gatekeeper + custom mail routing for every contact-form submission.
     */
    public function handleBeforeSend(SendEvent $event): void
    {
        $submission = $event->submission;
        $messageData = is_array($submission->message) ? $submission->message : [];

        // 1. Respect a spam verdict already set upstream (reCAPTCHA / honeypot plugin).
        if ($event->isSpam) {
            $event->toEmails = [];
            return;
        }

        // 2. Block the reCAPTCHA-disable bypass. Legitimate forms never send this;
        //    Contact Form Extensions skips reCAPTCHA entirely when
        //    message[disableRecaptcha] is truthy, so its mere presence is hostile.
        if (array_key_exists('disableRecaptcha', $messageData)) {
            $this->rejectAsSpam($event, 'disableRecaptcha param present');
            return;
        }

        // 3. Honeypot: a hidden field only bots fill in.
        if (!empty($messageData[self::HONEYPOT_FIELD])) {
            $this->rejectAsSpam($event, 'honeypot filled');
            return;
        }

        // 4. Per-IP rate limit.
        if ($this->isRateLimited()) {
            $this->rejectAsSpam($event, 'rate limit exceeded');
            return;
        }

        // --- Past the gate: genuine submission. We build and send our own emails,
        //     so stop the contact-form plugin from mailing its configured recipients. ---
        $event->toEmails = [];

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

    /**
     * Flags the submission as spam (so the contact-form Mailer skips its own send)
     * and stops this handler from sending anything.
     */
    private function rejectAsSpam(SendEvent $event, string $reason): void
    {
        $event->isSpam = true;
        $event->handled = true;
        $event->toEmails = [];

        $ip = Craft::$app->getRequest()->getUserIP() ?: 'unknown';
        Craft::warning("Contact form submission blocked ({$reason}) from {$ip}.", __METHOD__);
    }

    /**
     * Sliding-window per-IP rate limit backed by Craft's cache.
     */
    private function isRateLimited(): bool
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return false;
        }

        $ip = $request->getUserIP();
        if (!$ip) {
            return false;
        }

        $cache = Craft::$app->getCache();
        $key = 'fpFormRate:' . md5($ip);
        $count = (int)$cache->get($key);

        if ($count >= self::RATE_LIMIT) {
            return true;
        }

        $cache->set($key, $count + 1, self::RATE_WINDOW);
        return false;
    }

    private function _sendLandingPageEmails($submission, $entry, $systemEmail, $fromName, $adminRecipient) {
        try {
            $emailEntry = null;
            if ($entry->getFieldLayout() && $entry->getFieldLayout()->getFieldByHandle('emailMessageLink')) {
                $emailEntry = $entry->emailMessageLink->one() ?? null;
            }

            $guideAsset = Asset::find()->relatedTo($entry)->kind('pdf')->one();

            // USER CONFIRMATION
            $userMsg = new Message();
            $userMsg->setFrom([$systemEmail => $fromName]);
            $userMsg->setTo($submission->fromEmail);
            $userMsg->setSubject($emailEntry->emailHeadline ?? ($entry->title . " Guide"));

            $templateVars = ['submission' => $submission];
            if ($emailEntry) {
                $templateVars['emailEntry'] = $emailEntry;
            } else {
                $templateVars['hardcodedText'] = [
                    'headline' => 'Your ' . $entry->title . ' guide is here',
                    'body' => "Thanks for requesting the {$entry->title}. " .
                        ($guideAsset ? "It's attached to this email." : "One of our advisers will be in touch shortly.")
                ];
            }

            $userMsg->setHtmlBody(Craft::$app->getView()->renderTemplate('_emails/confirmation', $templateVars));

            // Attach PDF
            if ($guideAsset) {
                $path = $guideAsset->getCopyOfFile();
                if ($path && file_exists($path)) {
                    $userMsg->attach($path, ['fileName' => $guideAsset->filename]);
                } else {
                    Craft::warning("Could not attach PDF: File path invalid or missing.", __METHOD__);
                }
            }

            Craft::$app->getMailer()->send($userMsg);

            // ADMIN NOTIFICATION
            $adminMsg = new Message();
            $adminMsg->setFrom([$systemEmail => $fromName]);
            $adminMsg->setTo($adminRecipient);
            $adminMsg->setReplyTo($submission->fromEmail);

            $formName = $submission->message['formName'] ?? $entry->title;
            $cleanName = str_ireplace(' Guide', '', $formName);
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
