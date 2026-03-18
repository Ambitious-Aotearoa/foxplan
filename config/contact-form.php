<?php

use craft\helpers\App;

return [
    // The recipient of the FoxPlan enquiries
    'toEmail' => App::env('CONTACT_FORM_TO_EMAIL') ?: 'accounts@ambitious.co.nz',

    // The "From" email shown in the inbox
    'fromEmail' => App::env('MAILGUN_FROM_EMAIL') ?: 'accounts@ambitious.co.nz',
    'fromName' => App::env('MAILGUN_FROM_NAME') ?: 'FoxPlan',

    // Enable storing submissions in the database
    'enableDatabase' => true,

    'successFlashMessage' => 'Your message has been sent. We will be in touch soon.',
];