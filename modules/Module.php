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
                $submission = $event->submission;
                $formName = $submission->message['formName'] ?? '';

                $formNameToSection = [
                    'KiwiSaver Guide'         => 'kiwisaverLandingPage',
                    'First Home Buyers Guide' => 'firstHomeBuyers',
                ];

                if (isset($formNameToSection[$formName])) {
                    $sectionHandle = $formNameToSection[$formName];
                    $entry = Entry::find()->section($sectionHandle)->one();

                    if ($entry) {
                        // CORRECTED QUERY:
                        // kind('pdf') is the official way to filter AssetQueries for documents
                        $guideAsset = Asset::find()
                            ->relatedTo($entry)
                            ->kind('pdf')
                            ->one();

                        if ($guideAsset) {
                            $url = $guideAsset->getUrl();
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            $fileData = curl_exec($ch);
                            curl_close($ch);

                            if ($fileData) {
                                $message = new Message();
                                $message->setTo($submission->fromEmail);
                                $message->setSubject("Your {$formName}");

                                $html = Craft::$app->getView()->renderTemplate(
                                    '_emails/confirmation',
                                    ['submission' => $submission]
                                );

                                $message->setHtmlBody($html);

                                $message->attachContent($fileData, [
                                    'fileName'    => $guideAsset->filename,
                                    'contentType' => 'application/pdf',
                                    'disposition' => 'attachment',
                                ]);

                                Craft::$app->getMailer()->send($message);

                                // Mark as spam to kill the duplicate "Thanks" email
                                $event->isSpam = true;
                            }
                        }
                    }
                }
            }
        );
    }
}